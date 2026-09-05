<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use Closure;
use Kirby\Cms\App;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\CartError;
use ProgrammatorDev\StripeCheckout\Cart\CartOperation;
use ProgrammatorDev\StripeCheckout\Cart\CartRenderContext;
use ProgrammatorDev\StripeCheckout\Cart\Exception\CartException;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use Throwable;

/** @internal HTTP adaptation only; every mutation uses the supported PHP Cart API. */
final class CartEndpoint
{
    public const HEADERS = ['Cache-Control' => 'no-store, private', 'Vary' => 'Accept'];

    public function __construct(private readonly App $kirby) {}

    public function respond(CartOperation $operation, ?string $itemId = null): Response
    {
        $type = $this->representation();

        if ($type === null) {
            return new Response('', code: 406, headers: self::HEADERS);
        }

        $cart = null;
        $renderer = null;
        $error = null;
        $status = 200;
        $views = new CartViewFactory($this->kirby);

        try {
            /** @var array<string, mixed> $options */
            $options = $this->kirby->options();
            $renderer = (new ConfigurationResolver())->cartRenderer($options);

            // Negotiate before parsing or writing, including requests with invalid CSRF.
            if ($type === 'text/html' && $renderer === null) {
                return new Response('', code: 406, headers: self::HEADERS);
            }

            $input = $operation === CartOperation::Read ? [] : CartRequestParser::parse($this->kirby, $operation);
            $cart = (new RuntimeFactory($this->kirby))->cart();

            if ($cart === null) {
                return new Response('', code: 404, headers: self::HEADERS);
            }

            match ($operation) {
                CartOperation::Read => $cart,
                CartOperation::Add => $cart->add($input['reference'] ?? '', $input['quantity'] ?? 1, $input['options'] ?? []),
                CartOperation::Update => $cart->update($itemId ?? '', $input['quantity'] ?? 0, $input['revision'] ?? ''),
                CartOperation::Remove => $cart->remove($itemId ?? '', $input['revision'] ?? ''),
                CartOperation::Clear => $cart->clear($input['revision'] ?? ''),
            };
        } catch (CartException $failure) {
            $error = $this->httpError($failure->error());
            $cart = $failure->cart();
        } catch (CheckoutInputException $failure) {
            // Only our strict transport mapper can reach this catch directly.
            $error = $views->translatedError($failure->errorCode());
        } catch (Throwable $failure) {
            $error = $this->httpError($views->error($failure));
        }

        if ($error !== null) {
            $status = match ($error->code()) {
                'request.invalid_body' => 400,
                'request.csrf_invalid' => 403,
                'cart.item_not_found' => 404,
                'cart.revision_conflict' => 409,
                'request.unsupported_media_type' => 415,
                'product.resolution_unavailable' => 503,
                'internal.error' => 500,
                default => 422,
            };
        }

        if ($type === 'text/html') {
            return $this->html($renderer, $cart, new CartRenderContext($operation, $status, $error));
        }

        $body = [];

        if ($error !== null) {
            $body['error'] = CartResponseMapper::error($error);
        }

        if ($cart !== null) {
            $body['data'] = ['cart' => CartResponseMapper::cart($cart)];
        }

        return Response::json($body, code: $status, headers: self::HEADERS);
    }

    private function httpError(CartError $error): CartError
    {
        $code = match ($error->code()) {
            'cart.configuration_invalid' => 'configuration.not_ready',
            'cart.amount_invalid' => 'product.invalid',
            'cart.product_unavailable' => 'product.unavailable',
            'cart.selection_invalid' => 'selection.invalid',
            'cart.unavailable' => 'internal.error',
            'cart.provider_unavailable' => 'product.resolution_unavailable',
            'cart.quantity_invalid' => 'selection.quantity_invalid',
            'cart.line_limit_exceeded' => 'selection.line_limit_exceeded',
            default => $error->code(),
        };

        return new CartError($code, $error->message(), $error->itemId(), $code === 'cart.revision_conflict' ? 'revision' : $error->field());
    }

    private function html(?Closure $renderer, ?Cart $cart, CartRenderContext $context): Response
    {
        try {
            $html = $renderer?->__invoke($cart, $context);

            if (is_string($html) === false) {
                throw new \UnexpectedValueException();
            }

            return new Response($html, 'text/html', $context->status(), self::HEADERS);
        } catch (Throwable) {
            error_log('Stripe Checkout: cart.renderer_failed');
            // A committed mutation must not look retryable if only rendering failed.
            $status = $context->status() === 200
                ? ($context->operation() === CartOperation::Read ? 500 : 204)
                : $context->status();

            return new Response('', 'text/html', $status, self::HEADERS);
        }
    }

    private function representation(): ?string
    {
        $header = $this->kirby->request()->header('Accept', '*/*');
        $accept = is_string($header) ? $header : '';

        if (trim($accept) === '') {
            $accept = '*/*';
        }

        $best = null;
        $bestQuality = 0.0;

        // Kirby's preferredMimeType does not exclude q=0 or give an explicit
        // exclusion precedence over a wildcard. Apply that narrow HTTP rule here.
        foreach (['application/json', 'text/html'] as $type) {
            $quality = 0.0;
            $specificity = -1;

            foreach (explode(',', strtolower($accept)) as $range) {
                $parts = array_map('trim', explode(';', $range));
                $mime = array_shift($parts);
                $rank = match ($mime) {
                    $type => 2,
                    explode('/', $type)[0] . '/*' => 1,
                    '*/*' => 0,
                    default => -1,
                };

                if ($rank < 0 || $rank < $specificity) {
                    continue;
                }

                $q = 1.0;

                foreach ($parts as $parameter) {
                    if (str_starts_with($parameter, 'q=')) {
                        $q = is_numeric(substr($parameter, 2)) ? (float) substr($parameter, 2) : 0.0;
                    }
                }

                $specificity = $rank;
                $quality = $q >= 0 && $q <= 1 ? $q : 0.0;
            }

            if ($quality > $bestQuality) {
                $best = $type;
                $bestQuality = $quality;
            }
        }

        return $best;
    }
}
