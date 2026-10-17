<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use InvalidArgumentException;
use Kirby\Cms\App;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResultReason;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use Throwable;

/** @internal Validates hosted browser cancellation and returns to its saved destination. */
final class CheckoutCancelEndpoint
{
    public const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Referrer-Policy' => 'no-referrer',
    ];

    private const HTTP_SEE_OTHER = 303;

    private const MISSING_ORDER_REFERENCE_MESSAGE = 'Missing Checkout Order reference.';

    public function __construct(private readonly App $kirby) {}

    public function respond(): Response
    {
        $fallbackUrl = $this->kirby->site()->url();

        try {
            $pageUuid = $this->pageUuid();
            $runtime = new RuntimeFactory($this->kirby);
            $urlBuilder = new CheckoutUrlBuilder($this->kirby);
            $orderPageStore = $runtime->orderPageStore();
            $page = $orderPageStore->order($pageUuid);

            if ($page === null) {
                return $this->redirect($fallbackUrl);
            }

            $orderData = $orderPageStore->data($page);
            $checkoutAttempt = OrderData::map($orderData['checkoutAttempt']);

            if (UiMode::from(OrderData::string($checkoutAttempt['uiMode'])) !== UiMode::Hosted) {
                return $this->redirect($fallbackUrl);
            }

            // Use the destination frozen into this attempt.
            // Current configuration must not retarget a Session already handed to Stripe.
            $destinationUrl = OrderData::string($checkoutAttempt['cancelUrl']);
            $languageCode = OrderData::nullableString($orderData['languageCode'] ?? null);

            // Result references grant access to browser-session state and must never be sent to another origin.
            if (CheckoutUrlValidator::sameOrigin($destinationUrl, $urlBuilder->localizedSiteUrl($languageCode)) === false) {
                return $this->redirect($destinationUrl);
            }

            // Kirby exposes request URLs in this canonical form, so bind the result to the same representation its destination will read.
            $destinationUrl = CheckoutUrlBuilder::normalizedDestinationUrl($destinationUrl);

            try {
                $browserContext = (new BrowserAttemptContextResolver($this->kirby, $runtime->browserAttemptStore()))->forOrder(
                    pageUuid: $pageUuid,
                    orderData: $orderData,
                    checkoutAttempt: $checkoutAttempt,
                    uiMode: UiMode::Hosted,
                    checkedAt: new DateTimeImmutable(),
                );
            } catch (Throwable) {
                // Browser results are optional; cancellation must still reach the saved destination when session access fails.
                return $this->redirect($destinationUrl);
            }

            if ($browserContext !== null) {
                try {
                    $reference = $runtime->checkoutResultStore()->save(
                        reason: CheckoutResultReason::HostedCancel,
                        context: $browserContext,
                        pageUuid: $pageUuid,
                        destinationUrl: $destinationUrl,
                        savedAt: new DateTimeImmutable(),
                    );
                    $destinationUrl = $urlBuilder->checkoutResultUrl($destinationUrl, $reference);
                } catch (Throwable) {
                    // Cancellation remains usable when optional browser-result storage is unavailable.
                }
            }

            return $this->redirect($destinationUrl);
        } catch (InvalidArgumentException|OrderDataException|OrderQueryException|OrderStorageException) {
            // Cancellation is navigation only. Invalid or unavailable local state falls back without exposing Order facts.
            return $this->redirect($fallbackUrl);
        }
    }

    private function pageUuid(): string
    {
        $query = parse_url($this->kirby->request()->url()->toString(), PHP_URL_QUERY);

        if (is_string($query) === false) {
            throw new InvalidArgumentException(self::MISSING_ORDER_REFERENCE_MESSAGE);
        }

        parse_str($query, $parameters);
        $pageUuid = $parameters[CheckoutUrlBuilder::ORDER_QUERY_KEY] ?? null;

        if (is_string($pageUuid) === false) {
            throw new InvalidArgumentException(self::MISSING_ORDER_REFERENCE_MESSAGE);
        }

        return OrderData::uuid($pageUuid);
    }

    private function redirect(string $location): Response
    {
        return new Response('', code: self::HTTP_SEE_OTHER, headers: [
            ...self::HEADERS,
            'Location' => $location,
        ]);
    }
}
