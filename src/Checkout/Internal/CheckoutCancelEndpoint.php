<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use InvalidArgumentException;
use Kirby\Cms\App;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResultReason;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
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

    public function __construct(private readonly App $kirby) {}

    public function respond(): Response
    {
        $fallbackUrl = $this->kirby->site()->url();

        try {
            $pageUuid = $this->pageUuid();
            $runtime = new RuntimeFactory($this->kirby);
            $orders = $runtime->orderPageStore();
            $page = $orders->order($pageUuid);

            if ($page === null) {
                return $this->redirect($fallbackUrl);
            }

            $data = $orders->data($page);
            $checkoutAttempt = OrderData::map($data['checkoutAttempt']);

            if (UiMode::tryFrom(OrderData::string($checkoutAttempt['uiMode'])) !== UiMode::Hosted) {
                return $this->redirect($fallbackUrl);
            }

            // Use the destination frozen into this attempt.
            // Current configuration must not retarget a Session already handed to Stripe.
            $destinationUrl = OrderData::string($checkoutAttempt['cancelUrl']);
            $languageCode = OrderData::nullableString($data['languageCode'] ?? null);
            $userUuid = $this->kirby->user()?->uuid()->toString();
            $guestReference = BrowserAttemptContext::guestReferenceFor($userUuid, (string) $this->kirby->csrf());
            $browserContext = $runtime->browserAttemptStore()->findBoundContextForOrder(
                pageUuid: $pageUuid,
                tokenHash: OrderData::string($checkoutAttempt['tokenHash']),
                bindingFingerprint: OrderData::string($checkoutAttempt['bindingFingerprint']),
                userUuid: $userUuid,
                guestReference: $guestReference,
                checkedAt: new DateTimeImmutable(),
            );

            if ($browserContext !== null && $this->matchesOrderAttempt($browserContext, $data, $checkoutAttempt) === false) {
                $browserContext = null;
            }

            // Result references grant access to same-browser session state.
            // Never disclose them to an external destination.
            if ($browserContext !== null && CheckoutUrlValidator::sameOrigin($destinationUrl, $this->siteUrl($languageCode))) {
                try {
                    $reference = $runtime->checkoutResultStore()->save(
                        reason: CheckoutResultReason::HostedCancel,
                        context: $browserContext,
                        pageUuid: $pageUuid,
                        destinationUrl: $destinationUrl,
                        savedAt: new DateTimeImmutable(),
                    );
                    $destinationUrl = (new CheckoutUrlBuilder($this->kirby))->checkoutResultUrl($destinationUrl, $reference);
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
            throw new \InvalidArgumentException('Missing Checkout Order reference.');
        }

        parse_str($query, $parameters);
        $pageUuid = $parameters[CheckoutUrlBuilder::ORDER_QUERY_KEY] ?? null;

        if (is_string($pageUuid) === false) {
            throw new \InvalidArgumentException('Missing Checkout Order reference.');
        }

        return OrderData::uuid($pageUuid);
    }

    private function siteUrl(?string $languageCode): string
    {
        if ($this->kirby->multilang()) {
            $languageCode = $this->kirby->language($languageCode)?->code()
                ?? $this->kirby->defaultLanguage()?->code();
        } else {
            $languageCode = null;
        }

        return $this->kirby->site()->url($languageCode);
    }

    /** @param array<string, mixed> $data
     * @param array<string, mixed> $checkoutAttempt
     */
    private function matchesOrderAttempt(
        BrowserAttemptContext $browserContext,
        array $data,
        array $checkoutAttempt,
    ): bool {
        $checkoutSource = CheckoutSource::tryFrom(OrderData::string($checkoutAttempt['source']));
        $userUuid = OrderData::nullableString($data['userUuid'] ?? null);
        $guestReference = OrderData::nullableString($checkoutAttempt['guestReference']);

        return $checkoutSource !== null
            && $browserContext->checkoutSource() === $checkoutSource
            && $browserContext->uiMode() === UiMode::Hosted
            && $browserContext->languageCode() === OrderData::nullableString($data['languageCode'] ?? null)
            && $browserContext->cartRevision() === OrderData::nullableString($checkoutAttempt['cartRevision'])
            && $browserContext->matchesActor($userUuid, $guestReference);
    }

    private function redirect(string $location): Response
    {
        return new Response('', code: self::HTTP_SEE_OTHER, headers: [
            ...self::HEADERS,
            'Location' => $location,
        ]);
    }
}
