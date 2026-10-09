<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use Closure;
use Kirby\Cms\App;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\CartError;
use ProgrammatorDev\StripeCheckout\Cart\CartErrorCode;
use ProgrammatorDev\StripeCheckout\Cart\CartOperation;
use ProgrammatorDev\StripeCheckout\Cart\CartRenderContext;
use ProgrammatorDev\StripeCheckout\Cart\Exception\CartException;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\RequestErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\SelectionErrorCode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationErrorCode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Exception\InternalErrorCode;
use ProgrammatorDev\StripeCheckout\Http\ResponseNegotiator;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use Throwable;

/** @internal HTTP adaptation only; every mutation uses the supported PHP Cart API. */
final class CartEndpoint
{
    public const HEADERS = ['Cache-Control' => 'no-store, private', 'Vary' => 'Accept'];

    public function __construct(private readonly App $kirby) {}

    public function respond(CartOperation $operation, ?string $itemId = null): Response
    {
        $type = ResponseNegotiator::preferred($this->kirby, ['application/json', 'text/html']);

        if ($type === null) {
            return new Response('', code: 406, headers: self::HEADERS);
        }

        $cart = null;
        $renderer = null;
        $error = null;
        $status = 200;
        $views = (new RuntimeFactory($this->kirby))->cartViewFactory();

        try {
            /** @var array<string, mixed> $options */
            $options = $this->kirby->options();
            $renderer = (new ConfigurationResolver())->cartRenderer($options);

            // Negotiate before parsing or writing, including requests with invalid CSRF.
            if ($type === 'text/html' && $renderer === null) {
                return new Response('', code: 406, headers: self::HEADERS);
            }

            $runtime = new RuntimeFactory($this->kirby);

            switch ($operation) {
                case CartOperation::Read:
                    $cart = $runtime->cart(resolve: true);
                    break;
                case CartOperation::AddItem:
                    $productRequest = CartRequestParser::addItem($this->kirby);
                    $cart = $runtime->cart(resolve: false);
                    $cart?->add($productRequest->reference(), $productRequest->quantity(), $productRequest->selectedOptions());
                    break;
                case CartOperation::UpdateItem:
                    $itemUpdate = CartRequestParser::updateItem($this->kirby);
                    $cart = $runtime->cart(resolve: false);
                    $cart?->update($this->itemId($itemId), $itemUpdate->quantity(), $itemUpdate->revision());
                    break;
                case CartOperation::UpdateShippingCountry:
                    $shippingCountryUpdate = CartRequestParser::updateShippingCountry($this->kirby);
                    $cart = $runtime->cart(resolve: false);
                    $cart?->updateShippingCountry($shippingCountryUpdate->shippingCountry(), $shippingCountryUpdate->revision());
                    break;
                case CartOperation::RemoveItem:
                    $revision = CartRequestParser::removeItem($this->kirby);
                    $cart = $runtime->cart(resolve: false);
                    $cart?->remove($this->itemId($itemId), $revision);
                    break;
                case CartOperation::Clear:
                    $revision = CartRequestParser::clear($this->kirby);
                    $cart = $runtime->cart(resolve: false);
                    $cart?->clear($revision);
                    break;
                default:
                    throw new \LogicException();
            }

            if ($cart === null) {
                return new Response('', code: 404, headers: self::HEADERS);
            }
        } catch (CartException $failure) {
            $error = $this->httpError($failure->error());
            // Conflicts supply newer state; other rejections retain the cart already read instead of replacing its controls with "unavailable".
            $cart = $failure->cart() ?? $cart;
        } catch (CheckoutInputException $failure) {
            // Only our strict transport mapper can reach this catch directly.
            $error = $views->translatedError($failure->errorCode());
        } catch (Throwable $failure) {
            $error = $this->httpError($views->error($failure));
        }

        if ($error !== null) {
            $status = match ($error->code()) {
                RequestErrorCode::INVALID_BODY => 400,
                RequestErrorCode::CSRF_INVALID => 403,
                CartErrorCode::ITEM_NOT_FOUND => 404,
                CartErrorCode::REVISION_CONFLICT => 409,
                RequestErrorCode::UNSUPPORTED_MEDIA_TYPE => 415,
                ProductErrorCode::RESOLUTION_UNAVAILABLE => 503,
                InternalErrorCode::ERROR => 500,
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
            CartErrorCode::CONFIGURATION_INVALID => ConfigurationErrorCode::NOT_READY,
            CartErrorCode::AMOUNT_INVALID => ProductErrorCode::INVALID,
            CartErrorCode::PRODUCT_UNAVAILABLE => ProductErrorCode::UNAVAILABLE,
            CartErrorCode::SELECTION_INVALID => SelectionErrorCode::INVALID,
            CartErrorCode::UNAVAILABLE => InternalErrorCode::ERROR,
            CartErrorCode::PROVIDER_UNAVAILABLE => ProductErrorCode::RESOLUTION_UNAVAILABLE,
            CartErrorCode::QUANTITY_INVALID => SelectionErrorCode::QUANTITY_INVALID,
            CartErrorCode::LINE_LIMIT_EXCEEDED => SelectionErrorCode::LINE_LIMIT_EXCEEDED,
            default => $error->code(),
        };

        return new CartError($code, $error->message(), $error->itemId(), $code === CartErrorCode::REVISION_CONFLICT ? 'revision' : $error->field());
    }

    private function itemId(?string $itemId): string
    {
        if ($itemId === null || $itemId === '') {
            throw new CheckoutInputException(SelectionErrorCode::INVALID);
        }

        return $itemId;
    }

    private function html(?Closure $renderer, ?Cart $cart, CartRenderContext $context): Response
    {
        try {
            $html = $renderer?->__invoke($cart, $context);

            if (is_string($html) === false) {
                throw new \UnexpectedValueException();
            }

            return new Response($html, 'text/html', $context->httpStatus(), self::HEADERS);
        } catch (Throwable) {
            error_log('Stripe Checkout: ' . CartErrorCode::RENDERER_FAILED);
            // A committed mutation must not look retryable if only rendering failed.
            $status = $context->httpStatus() === 200
                ? ($context->operation() === CartOperation::Read ? 500 : 204)
                : $context->httpStatus();

            return new Response('', 'text/html', $status, self::HEADERS);
        }
    }
}
