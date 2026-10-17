<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResult;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResultReason;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Payment;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ValueError;

/** @internal Consumes the current destination reference and projects safe Order facts. */
final readonly class CheckoutResultResolver
{
    public function __construct(
        private App $kirby,
        private CheckoutResultStore $results,
        private OrderPageStore $orders,
    ) {}

    public function resolve(): ?CheckoutResult
    {
        if ($this->kirby->request()->method() !== 'GET') {
            return null;
        }

        $requestUrl = $this->kirby->request()->url()->toString();
        $query = parse_url($requestUrl, PHP_URL_QUERY);

        if (is_string($query) === false) {
            return null;
        }

        parse_str($query, $parameters);
        $reference = $parameters[CheckoutUrlValidator::RESULT_QUERY_KEY] ?? null;

        if (is_string($reference) === false) {
            return null;
        }

        $userUuid = $this->kirby->user()?->uuid()->toString();
        $guestReference = BrowserAttemptContext::guestReferenceFor($userUuid, (string) $this->kirby->csrf());
        $state = $this->results->consume(
            reference: $reference,
            userUuid: $userUuid,
            guestReference: $guestReference,
            destinationUrl: CheckoutUrlBuilder::resultDestinationUrl($requestUrl),
            checkedAt: new DateTimeImmutable(),
        );

        if ($state === null) {
            return null;
        }

        $pageUuid = $state->pageUuid();

        if ($pageUuid === null) {
            return $this->unavailable($state->reason());
        }

        try {
            $page = $this->orders->order($pageUuid);

            if ($page === null) {
                return $this->unavailable($state->reason());
            }

            $data = $this->orders->data($page);
            $checkoutStatus = CheckoutStatus::from(OrderData::string($data['checkoutStatus']));
            $paymentStatus = PaymentStatus::from(OrderData::string($data['paymentStatus']));
            $paymentData = isset($data['payment']) ? OrderData::map($data['payment']) : null;
            $payment = $paymentData === null ? null : Payment::fromArray($paymentData);
            $nextAction = $state->nextAction();

            if (
                $paymentData !== null
                && $nextAction !== null
                && $checkoutStatus !== CheckoutStatus::Expired
                && in_array($paymentStatus, [PaymentStatus::Unpaid, PaymentStatus::Pending], true)
                && $payment?->stripePaymentIntentId() === $state->paymentIntentId()
            ) {
                // The one-time action remains usable only while current canonical payment facts still match its provider observation.
                $payment = Payment::fromArray($paymentData, nextAction: $nextAction);
            }

            $canResumeCheckout = $state->canResumeCheckout()
                && $state->reason() === CheckoutResultReason::EmbeddedReturn
                && $checkoutStatus === CheckoutStatus::Open
                && OrderData::date($data['checkoutExpiresAt']) > new DateTimeImmutable();

            return new CheckoutResult(
                reason: $state->reason(),
                order: $page,
                checkoutStatus: $checkoutStatus,
                paymentStatus: $paymentStatus,
                payment: $payment,
                canResumeCheckout: $canResumeCheckout,
            );
        } catch (OrderDataException|OrderQueryException|OrderStorageException|ValueError) {
            return $this->unavailable($state->reason());
        }
    }

    private function unavailable(CheckoutResultReason $reason): CheckoutResult
    {
        return new CheckoutResult(
            reason: $reason,
            order: null,
            checkoutStatus: null,
            paymentStatus: null,
            payment: null,
            canResumeCheckout: false,
        );
    }
}
