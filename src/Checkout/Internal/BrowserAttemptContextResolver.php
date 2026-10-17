<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;

/** @internal Resolves one accepted browser attempt against its persisted Order facts. */
final readonly class BrowserAttemptContextResolver
{
    public function __construct(
        private App $kirby,
        private BrowserAttemptStore $attemptStore,
    ) {}

    /**
     * @param array<string, mixed> $orderData
     * @param array<string, mixed> $checkoutAttempt
     */
    public function forOrder(
        string $pageUuid,
        array $orderData,
        array $checkoutAttempt,
        UiMode $uiMode,
        DateTimeImmutable $checkedAt,
    ): ?BrowserAttemptContext {
        $checkoutSource = CheckoutSource::from(OrderData::string($checkoutAttempt['source']));
        $userUuid = $this->kirby->user()?->uuid()->toString();
        $guestReference = BrowserAttemptContext::guestReferenceFor($userUuid, (string) $this->kirby->csrf());
        $browserContext = $this->attemptStore->findBoundContextForOrder(
            pageUuid: $pageUuid,
            tokenHash: OrderData::string($checkoutAttempt['tokenHash']),
            bindingFingerprint: OrderData::string($checkoutAttempt['bindingFingerprint']),
            userUuid: $userUuid,
            guestReference: $guestReference,
            checkedAt: $checkedAt,
        );

        if ($browserContext === null) {
            return null;
        }

        if ($browserContext->checkoutSource() !== $checkoutSource) {
            return null;
        }

        if ($browserContext->uiMode() !== $uiMode) {
            return null;
        }

        if ($browserContext->languageCode() !== OrderData::nullableString($orderData['languageCode'] ?? null)) {
            return null;
        }

        if ($browserContext->cartRevision() !== OrderData::nullableString($checkoutAttempt['cartRevision'])) {
            return null;
        }

        $orderUserUuid = OrderData::nullableString($orderData['userUuid'] ?? null);
        $orderGuestReference = OrderData::nullableString($checkoutAttempt['guestReference']);

        return $browserContext->matchesActor($orderUserUuid, $orderGuestReference) ? $browserContext : null;
    }
}
