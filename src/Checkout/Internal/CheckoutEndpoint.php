<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\InvalidSessionRequestException;
use ProgrammatorDev\StripeCheckout\Checkout\RequestErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\SelectionErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationErrorCode;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Exception\InternalErrorCode;
use ProgrammatorDev\StripeCheckout\Exception\MoneyException;
use ProgrammatorDev\StripeCheckout\Http\ResponseNegotiator;
use ProgrammatorDev\StripeCheckout\Kirby\PersistenceErrorCode;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\OrderErrorCode;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductException;
use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\ShippingException;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Tax\TaxErrorCode;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;
use ProgrammatorDev\StripeCheckout\Translation\LocaleResolver;
use Throwable;

/** @internal Adapts browser-authorized Checkout creation to private JSON responses. */
final class CheckoutEndpoint
{
    public const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Vary' => 'Accept',
        'Referrer-Policy' => 'no-referrer',
    ];

    private const HTTP_OK = 200;
    private const HTTP_CREATED = 201;
    private const HTTP_ACCEPTED = 202;
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_FORBIDDEN = 403;
    private const HTTP_NOT_ACCEPTABLE = 406;
    private const HTTP_CONFLICT = 409;
    private const HTTP_UNPROCESSABLE_ENTITY = 422;
    private const HTTP_INTERNAL_SERVER_ERROR = 500;
    private const HTTP_BAD_GATEWAY = 502;
    private const HTTP_SERVICE_UNAVAILABLE = 503;

    public function __construct(private readonly App $kirby) {}

    public function respond(): Response
    {
        // Negotiation precedes session binding, project callbacks and all creation work.
        if (ResponseNegotiator::preferred($this->kirby, ['application/json']) === null) {
            return new Response('', code: self::HTTP_NOT_ACCEPTABLE, headers: self::HEADERS);
        }

        try {
            $input = CheckoutSubmissionParser::parse($this->kirby);

            // Hosted form redirects and their holding/error views are implemented together in the following step.
            if ($input->isJson() === false) {
                return new Response('', code: self::HTTP_NOT_ACCEPTABLE, headers: self::HEADERS);
            }

            // Use one timestamp for this submission's binding and creation work.
            // Duplicates retain their original acceptance time and saved creation deadlines.
            $acceptedAt = new DateTimeImmutable();
            $runtime = new RuntimeFactory($this->kirby);
            $submission = $runtime->checkoutSubmissionFactory()->create(input: $input, acceptedAt: $acceptedAt);
            $attempt = $submission->attempt();
            $presentation = $runtime->checkoutSessionCreator()->create(
                checkout: $submission->checkout(),
                shipping: $submission->shipping(),
                binding: $submission->binding(),
                token: $attempt->token(),
                guestReference: $attempt->context()->guestReference(),
                now: $acceptedAt,
                initiatingUrl: $attempt->initiatingUrl(),
            );
            // The creator validates and authorizes presentation; project only the configured mode's ephemeral credential.
            $data = ['uiMode' => $presentation->uiMode()->value];

            if ($presentation->uiMode() === UiMode::Hosted) {
                $data['redirectUrl'] = $presentation->redirectUrl();
            } else {
                $data['clientSecret'] = $presentation->clientSecret();
            }

            return Response::json([
                'ok' => true,
                'data' => $data,
            ], code: $presentation->isReused() ? self::HTTP_OK : self::HTTP_CREATED, headers: self::HEADERS);
        } catch (Throwable $failure) {
            return $this->failure($failure);
        }
    }

    private function failure(Throwable $failure): Response
    {
        $code = match (true) {
            $failure instanceof CheckoutInputException => $this->inputErrorCode($failure),
            $failure instanceof CheckoutSessionException => $failure->errorCode(),
            $failure instanceof ConfigurationException => $failure->errorCode() === ConfigurationErrorCode::CREDENTIAL_MODE_MISMATCH
                ? ConfigurationErrorCode::CREDENTIAL_MODE_MISMATCH : ConfigurationErrorCode::NOT_READY,
            $failure instanceof ProductException => $this->productErrorCode($failure),
            $failure instanceof ShippingException => ShippingErrorCode::INVALID,
            $failure instanceof InvalidSessionRequestException => $failure->errorCode(),
            $failure instanceof MoneyException => ProductErrorCode::INVALID,
            $failure instanceof OrderStorageException => PersistenceErrorCode::ORDER_UNAVAILABLE,
            $failure instanceof OrderDataException && $failure->errorCode() === OrderErrorCode::NUMBER_INVALID => OrderErrorCode::NUMBER_INVALID,
            default => InternalErrorCode::ERROR,
        };
        $httpStatus = match ($code) {
            RequestErrorCode::INVALID_BODY,
            SelectionErrorCode::INVALID, SelectionErrorCode::QUANTITY_INVALID, SelectionErrorCode::LINE_LIMIT_EXCEEDED => self::HTTP_BAD_REQUEST,
            RequestErrorCode::CSRF_INVALID, RequestErrorCode::ORIGIN_INVALID => self::HTTP_FORBIDDEN,
            RequestErrorCode::UNSUPPORTED_REPRESENTATION, RequestErrorCode::UNSUPPORTED_MEDIA_TYPE => self::HTTP_NOT_ACCEPTABLE,
            CheckoutErrorCode::ATTEMPT_TOKEN_INVALID, CheckoutErrorCode::ATTEMPT_CONFLICT,
            CheckoutErrorCode::ATTEMPT_CLOSED, CheckoutErrorCode::ATTEMPT_RETRY_EXPIRED,
            CheckoutErrorCode::CART_DISABLED, CheckoutErrorCode::SESSION_MISSING,
            ProductErrorCode::UNAVAILABLE, ProductErrorCode::PRICE_SOURCE_MISMATCH,
            ShippingErrorCode::COUNTRY_REQUIRED, ShippingErrorCode::COUNTRY_INVALID, ShippingErrorCode::UNAVAILABLE,
            ConfigurationErrorCode::CREDENTIAL_MODE_MISMATCH => self::HTTP_CONFLICT,
            ProductErrorCode::INVALID, ShippingErrorCode::INVALID, OrderErrorCode::NUMBER_INVALID,
            SessionRequestErrorCode::FILTER_FAILED, SessionRequestErrorCode::FILTER_INVALID,
            SessionRequestErrorCode::INVARIANT_VIOLATION, SessionRequestErrorCode::PARAMETER_INVALID,
            SessionRequestErrorCode::PARAMETER_PROTECTED => self::HTTP_UNPROCESSABLE_ENTITY,
            ConfigurationErrorCode::NOT_READY, PersistenceErrorCode::ORDER_UNAVAILABLE,
            ProductErrorCode::RESOLUTION_UNAVAILABLE, CheckoutErrorCode::SESSION_UNAVAILABLE,
            CheckoutErrorCode::SESSION_ATTACHMENT_FAILED => self::HTTP_SERVICE_UNAVAILABLE,
            CheckoutErrorCode::SESSION_REJECTED, CheckoutErrorCode::SESSION_INCOMPATIBLE => self::HTTP_BAD_GATEWAY,
            CheckoutErrorCode::SESSION_UNCERTAIN => self::HTTP_ACCEPTED,
            default => self::HTTP_INTERNAL_SERVER_ERROR,
        };

        // Callback exceptions may supply arbitrary codes; neither their code nor their message is public authority.
        if ($httpStatus === self::HTTP_INTERNAL_SERVER_ERROR) {
            $code = InternalErrorCode::ERROR;
        }

        // A failed read and an uncertain mutation can both be retryable; only the latter receives 202.
        $retryable = $failure instanceof CheckoutSessionException
            ? $failure->isRetryable()
            : in_array($code, [ProductErrorCode::RESOLUTION_UNAVAILABLE, PersistenceErrorCode::ORDER_UNAVAILABLE], true);

        return Response::json([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $this->message($code),
                'retryable' => $httpStatus !== self::HTTP_INTERNAL_SERVER_ERROR && $retryable,
            ],
        ], code: $httpStatus, headers: self::HEADERS);
    }

    private function inputErrorCode(CheckoutInputException $failure): string
    {
        $code = $failure->errorCode();

        if (in_array($code, [ShippingErrorCode::COUNTRY_REQUIRED, ShippingErrorCode::COUNTRY_INVALID], true)) {
            return $code;
        }

        // Custom quotes expose shipping.* reason codes; retain the unavailable outcome without publishing custom details.
        return str_starts_with($code, 'shipping.') ? ShippingErrorCode::UNAVAILABLE : $code;
    }

    private function productErrorCode(ProductException $failure): string
    {
        return match ($failure->errorCode()) {
            ProductErrorCode::REQUEST_INVALID, ProductErrorCode::SELECTED_OPTIONS_INVALID => SelectionErrorCode::INVALID,
            ProductErrorCode::NOT_FOUND, ProductErrorCode::UNAVAILABLE, ProductErrorCode::VARIANT_UNAVAILABLE,
            ProductErrorCode::STRIPE_PRICE_INELIGIBLE, ProductErrorCode::STRIPE_PRODUCT_INELIGIBLE => ProductErrorCode::UNAVAILABLE,
            ProductErrorCode::STRIPE_PRICE_UNAVAILABLE, ProductErrorCode::RESOLVER_FAILED,
            TaxErrorCode::CATALOGUE_UNAVAILABLE => ProductErrorCode::RESOLUTION_UNAVAILABLE,
            ProductErrorCode::PRICE_SOURCE_MISMATCH => ProductErrorCode::PRICE_SOURCE_MISMATCH,
            default => ProductErrorCode::INVALID,
        };
    }

    private function message(string $code): string
    {
        $key = Catalogue::PREFIX . $code;
        $locale = 'en';

        try {
            $locale = (new LocaleResolver($this->kirby))->resolve();
        } catch (Throwable) {
        }

        $candidates = array_unique(array_filter([$this->kirby->language()?->code(), $locale, explode('_', $locale)[0], 'en']));

        foreach ($candidates as $candidate) {
            $message = $this->kirby->translation($candidate)->get($key);

            if ($message !== null) {
                return $message;
            }
        }

        return Catalogue::bundled()['en'][$key];
    }
}
