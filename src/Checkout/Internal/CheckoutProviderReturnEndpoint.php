<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use InvalidArgumentException;
use Kirby\Cms\App;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResultReason;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use Throwable;
use ValueError;

/** @internal Reconciles one hosted-success or embedded-return navigation before redirecting. */
final class CheckoutProviderReturnEndpoint
{
    public const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Referrer-Policy' => 'no-referrer',
    ];

    private const HTTP_SEE_OTHER = 303;

    private const MISSING_ORDER_REFERENCE_MESSAGE = 'Missing Checkout Order reference.';

    private const MISSING_SESSION_REFERENCE_MESSAGE = 'Missing Checkout Session reference.';

    public function __construct(
        private readonly App $kirby,
        private readonly UiMode $uiMode,
    ) {}

    public function respond(): Response
    {
        $fallbackUrl = $this->kirby->site()->url();

        try {
            $query = parse_url($this->kirby->request()->url()->toString(), PHP_URL_QUERY);

            if (is_string($query) === false) {
                throw new InvalidArgumentException(self::MISSING_ORDER_REFERENCE_MESSAGE);
            }

            parse_str($query, $parameters);
            $pageUuid = $parameters[CheckoutUrlBuilder::ORDER_QUERY_KEY] ?? null;
            $sessionId = $parameters[CheckoutUrlBuilder::SESSION_ID_QUERY_KEY] ?? null;

            if (is_string($pageUuid) === false) {
                throw new InvalidArgumentException(self::MISSING_ORDER_REFERENCE_MESSAGE);
            }

            if (is_string($sessionId) === false) {
                throw new InvalidArgumentException(self::MISSING_SESSION_REFERENCE_MESSAGE);
            }

            $pageUuid = OrderData::uuid($pageUuid);
            $sessionId = OrderData::nonEmptyString($sessionId);
            $runtime = new RuntimeFactory($this->kirby);
            $orderPageStore = $runtime->orderPageStore();
            $page = $orderPageStore->order($pageUuid);

            if ($page === null) {
                return $this->redirect($fallbackUrl);
            }

            $orderData = $orderPageStore->data($page);
            $checkoutAttempt = OrderData::map($orderData['checkoutAttempt']);

            if (UiMode::from(OrderData::string($checkoutAttempt['uiMode'])) !== $this->uiMode) {
                return $this->redirect($fallbackUrl);
            }

            $destinationUrl = $this->fallbackDestination($checkoutAttempt);
            $browserContext = $this->browserContext($runtime, $pageUuid, $orderData, $checkoutAttempt);
            $checkoutStatus = null;
            $savedSessionId = $orderData['stripeCheckoutSessionId'] ?? null;

            // The query Session ID is untrusted. Read Stripe only after it exactly matches the Session persisted for this Order.
            if ($savedSessionId === $sessionId) {
                try {
                    $page = $runtime->checkoutSessionReconciler()->reconcile($pageUuid, $sessionId);
                    $orderData = $orderPageStore->data($page);
                    $checkoutStatus = CheckoutStatus::from(OrderData::string($orderData['checkoutStatus']));
                    $destinationUrl = $this->destination($checkoutAttempt, $checkoutStatus);
                } catch (CheckoutSessionException|ConfigurationException|OrderDataException|OrderQueryException|OrderStorageException|ValueError) {
                    // Browser navigation cannot establish provider facts. Preserve the saved safe destination and expose only an unavailable result.
                }
            }

            // Checkout completion freezes the purchase even when payment remains pending; clear only the unchanged initiating Cart.
            if ($checkoutStatus === CheckoutStatus::Complete && $browserContext !== null) {
                $this->clearOriginatingCart($runtime, $browserContext);
            }

            return $this->redirectWithResult(
                runtime: $runtime,
                destinationUrl: $destinationUrl,
                pageUuid: $checkoutStatus === null ? null : $pageUuid,
                browserContext: $browserContext,
            );
        } catch (InvalidArgumentException|OrderDataException|OrderQueryException|OrderStorageException) {
            return $this->redirect($fallbackUrl);
        }
    }

    /**
     * @param array<string, mixed> $orderData
     * @param array<string, mixed> $checkoutAttempt
     */
    private function browserContext(
        RuntimeFactory $runtime,
        string $pageUuid,
        array $orderData,
        array $checkoutAttempt,
    ): ?BrowserAttemptContext {
        try {
            return (new BrowserAttemptContextResolver($this->kirby, $runtime->browserAttemptStore()))->forOrder(
                pageUuid: $pageUuid,
                orderData: $orderData,
                checkoutAttempt: $checkoutAttempt,
                uiMode: $this->uiMode,
                checkedAt: new DateTimeImmutable(),
            );
        } catch (Throwable) {
            // Reconciliation remains authoritative when optional browser-session correlation is unavailable.
            return null;
        }
    }

    private function clearOriginatingCart(RuntimeFactory $runtime, BrowserAttemptContext $context): void
    {
        if ($context->checkoutSource() !== CheckoutSource::Cart) {
            return;
        }

        $cartId = $context->cartId();
        $cartRevision = $context->cartRevision();

        if ($cartId === null || $cartRevision === null) {
            return;
        }

        try {
            $runtime->cartMutator()->clearIfMatches($cartId, $cartRevision);
        } catch (Throwable) {
            // Cart cleanup is browser-local follow-up; it must not replace the committed Order result or block navigation.
        }
    }

    private function redirectWithResult(
        RuntimeFactory $runtime,
        string $destinationUrl,
        ?string $pageUuid,
        ?BrowserAttemptContext $browserContext,
    ): Response {
        if ($browserContext === null) {
            return $this->redirect($destinationUrl);
        }

        $urlBuilder = new CheckoutUrlBuilder($this->kirby);
        $siteUrl = $urlBuilder->localizedSiteUrl($browserContext->languageCode());

        // Result references grant session-bound Order access and must not be disclosed to another origin.
        if (CheckoutUrlValidator::sameOrigin($destinationUrl, $siteUrl) === false) {
            return $this->redirect($destinationUrl);
        }

        try {
            $reference = $runtime->checkoutResultStore()->save(
                reason: $this->reason(),
                context: $browserContext,
                pageUuid: $pageUuid,
                destinationUrl: $destinationUrl,
                savedAt: new DateTimeImmutable(),
            );
            $destinationUrl = $urlBuilder->checkoutResultUrl($destinationUrl, $reference);
        } catch (Throwable) {
            // The authoritative Order is already committed; optional presentation state must not block navigation.
        }

        return $this->redirect($destinationUrl);
    }

    /** @param array<string, mixed> $checkoutAttempt */
    private function fallbackDestination(array $checkoutAttempt): string
    {
        return match ($this->uiMode) {
            UiMode::Hosted => OrderData::string($checkoutAttempt['successUrl']),
            UiMode::Embedded => OrderData::string($checkoutAttempt['returnUrl']),
        };
    }

    /** @param array<string, mixed> $checkoutAttempt */
    private function destination(array $checkoutAttempt, CheckoutStatus $checkoutStatus): string
    {
        if ($this->uiMode === UiMode::Embedded && $checkoutStatus !== CheckoutStatus::Complete) {
            return OrderData::string($checkoutAttempt['returnUrl']);
        }

        return OrderData::string($checkoutAttempt['successUrl']);
    }

    private function reason(): CheckoutResultReason
    {
        return match ($this->uiMode) {
            UiMode::Hosted => CheckoutResultReason::HostedSuccess,
            UiMode::Embedded => CheckoutResultReason::EmbeddedReturn,
        };
    }

    private function redirect(string $location): Response
    {
        return new Response('', code: self::HTTP_SEE_OTHER, headers: [
            ...self::HEADERS,
            'Location' => $location,
        ]);
    }
}
