<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\Language;
use Kirby\Form\Fields;
use Kirby\Panel\Page;
use Kirby\Panel\Panel;
use Kirby\Panel\Ui\Buttons\ViewButtons;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\HookDeliveryLedger;
use ProgrammatorDev\StripeCheckout\Money\MoneyFormatter;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;
use ProgrammatorDev\StripeCheckout\Order\PaymentAction;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;
use Stripe\Dispute;
use Stripe\Refund;

/** @internal Customizes the native Order Page presentation without replacing its route or form. */
final class OrderPagePanel extends Page
{
    public const PREFIX = OrderSchema::PRESENTATION_FIELD_PREFIX;

    /**
     * Share one validated snapshot between the native versions and buttons projections.
     *
     * @var array<string, mixed>|null
     */
    private ?array $orderData = null;

    /** @var list<string> */
    public const CHECKOUT_ATTEMPT_FIELDS = [
        'tokenHash',
        'bindingFingerprint',
        'requestFingerprint',
        'idempotencyKey',
        'stripeApiVersion',
        'credentialMode',
        'credentialFingerprint',
        'operation',
        'retryUntil',
        'source',
        'cartRevision',
        'guestReference',
        'uiMode',
        'initiatingUrl',
        'successUrl',
        'cancelUrl',
        'returnUrl',
    ];

    /** @return list<array{label: string, link: string}> */
    public function breadcrumb(): array
    {
        return [[
            'label' => self::translate('tabs.orders'),
            'link' => Panel::url('stripe-checkout') . '?tab=orders',
        ], [
            'label' => $this->model->title()->toString(),
            'link' => $this->url(true),
        ]];
    }

    /** @return array<mixed> */
    public function buttons(): array
    {
        $model = $this->model;

        return ViewButtons::view($this)
            ->bind([
                'hasRetryableLifecycleDelivery' => $this->hasRetryableLifecycleDelivery(),
                'isManuallyDeletable' => $this->isManuallyDeletable(),
                'orderId' => $model instanceof OrderPage ? $model->slug() : null,
                'stripeCheckoutSessionId' => $this->stripeCheckoutSessionId(),
                'stripeDashboardUrl' => $this->stripeDashboardUrl(),
            ])
            ->defaults('open', 'preview', '-', 'settings', 'languages', 'status')
            ->render();
    }

    /** @return array<mixed> */
    public function view(): array
    {
        // Orders use Kirby's native Page route; replace only its site-area
        // navigation metadata with the owning plugin area.
        return [
            ...parent::view(),
            'breadcrumbLabel' => self::translate('area.label'),
            'icon' => 'credit-card',
            'link' => Panel::url('stripe-checkout'),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function versions(): array
    {
        $versions = parent::versions();
        $latest = self::map($versions['latest'] ?? null);
        $changes = self::map($versions['changes'] ?? null);
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return [
                'latest' => $latest,
                'changes' => $changes,
            ];
        }

        $projectionInput = self::projection(
            order: $this->orderData($model),
            panelLocale: $this->panelLocale(),
            moneyFormatter: new MoneyFormatter($model->kirby()),
        );

        // Fields formats the entire blueprint. Keep only the supplied projection
        // so empty display fields cannot overwrite the native version values.
        $projection = array_intersect_key(self::map(
            Fields::for($model, Language::ensure('current'))
                ->fill(
                    input: $projectionInput,
                    passthrough: false,
                )
                ->toFormValues(),
        ), $projectionInput);

        return [
            'latest' => [...$latest, ...$projection],
            'changes' => [...$changes, ...$projection],
        ];
    }

    public function stripeDashboardUrl(): ?string
    {
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return null;
        }

        return self::stripeDashboardUrlFor($this->orderData($model));
    }

    /**
     * Supplies the native stats section with presentation-only order states.
     *
     * @return list<array{label: string, value: string, icon: string, theme: string}>
     */
    public function statusReports(): array
    {
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return [];
        }

        $order = $this->orderData($model);
        $checkoutStatus = CheckoutStatus::from(OrderData::string($order['checkoutStatus'] ?? null));
        $paymentStatus = PaymentStatus::from(OrderData::string($order['paymentStatus'] ?? null));
        $refundStatus = RefundStatus::from(OrderData::string($order['refundStatus'] ?? null));
        $refundRequiresAction = OrderData::boolean($order['refundRequiresAction'] ?? null);
        $disputeStatus = DisputeStatus::from(OrderData::string($order['disputeStatus'] ?? null));
        // A successful payment makes these outcomes possible; reconciled provider records remain authoritative exceptions.
        $refundApplicable = $paymentStatus === PaymentStatus::Paid
            || $refundStatus !== RefundStatus::None
            || $refundRequiresAction;
        $disputeApplicable = $paymentStatus === PaymentStatus::Paid
            || $disputeStatus !== DisputeStatus::None;

        return [
            self::statusReport(
                name: 'checkoutStatus',
                value: self::translate('orders.index.option.checkout.' . $checkoutStatus->value),
                theme: match ($checkoutStatus) {
                    CheckoutStatus::Complete => 'positive',
                    CheckoutStatus::CreationFailed => 'negative',
                    CheckoutStatus::CreationUncertain, CheckoutStatus::Expired => 'notice',
                    default => 'info',
                },
            ),
            self::statusReport(
                name: 'paymentStatus',
                value: self::translate('orders.index.option.payment.' . $paymentStatus->value),
                theme: match ($paymentStatus) {
                    PaymentStatus::Paid, PaymentStatus::NoPaymentRequired => 'positive',
                    PaymentStatus::Failed => 'negative',
                    PaymentStatus::Pending => 'notice',
                    PaymentStatus::Unpaid => $checkoutStatus === CheckoutStatus::Complete ? 'notice' : 'info',
                },
            ),
            self::statusReport(
                name: 'refundStatus',
                value: match (true) {
                    $refundApplicable === false => self::translate('orders.status.notApplicable'),
                    $refundRequiresAction => self::translate('orders.fields.refundRequiresAction'),
                    default => self::translate('orders.index.option.refundFilter.' . $refundStatus->value),
                },
                theme: match (true) {
                    $refundApplicable === false => 'passive',
                    $refundRequiresAction => 'negative',
                    $refundStatus === RefundStatus::None => 'positive',
                    in_array($refundStatus, [RefundStatus::Pending, RefundStatus::Partial], true) => 'notice',
                    $refundStatus === RefundStatus::Full => 'info',
                    $refundStatus === RefundStatus::Failed => 'negative',
                },
            ),
            self::statusReport(
                name: 'disputeStatus',
                value: $disputeApplicable
                    ? self::translate('orders.index.option.disputeFilter.' . $disputeStatus->value)
                    : self::translate('orders.status.notApplicable'),
                theme: match (true) {
                    $disputeApplicable === false => 'passive',
                    in_array($disputeStatus, [DisputeStatus::None, DisputeStatus::ResolvedFavorable], true) => 'positive',
                    in_array($disputeStatus, [DisputeStatus::UnderReview, DisputeStatus::Mixed], true) => 'notice',
                    in_array($disputeStatus, [DisputeStatus::NeedsResponse, DisputeStatus::ResolvedLost], true) => 'negative',
                },
            ),
        ];
    }

    /**
     * Payment details omit the Checkout state that the Order Overview already presents.
     *
     * @return list<array{label: string, value: string, icon: string, theme: string}>
     */
    public function financialStatusReports(): array
    {
        return array_slice($this->statusReports(), 1);
    }

    /** Native Info fields resolve these notices through the Page's Panel query API. */
    public function refundRequiresActionNotice(): string
    {
        return $this->refundNotice(
            status: Refund::STATUS_REQUIRES_ACTION,
            translationKey: 'orders.refundAttention.requiresAction',
        );
    }

    public function refundFailureNotice(): string
    {
        return $this->refundNotice(
            status: Refund::STATUS_FAILED,
            translationKey: 'orders.refundAttention.failed',
            reasonField: 'failureReason',
        );
    }

    public function disputeResponseNotice(): string
    {
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return '';
        }

        $statuses = [
            Dispute::STATUS_NEEDS_RESPONSE,
            Dispute::STATUS_APPEAL_NEEDS_RESPONSE,
            Dispute::STATUS_WARNING_NEEDS_RESPONSE,
        ];
        $items = [];
        $moneyFormatter = new MoneyFormatter($model->kirby());

        foreach (self::list($this->orderData($model)['disputes'] ?? null) as $value) {
            $dispute = self::map($value);

            if (in_array($dispute['status'] ?? null, $statuses, true) === false) {
                continue;
            }

            $amount = self::formattedAmount(
                value: $dispute['amount'] ?? null,
                currency: OrderData::string($dispute['currency'] ?? null),
                panelLocale: $this->panelLocale(),
                moneyFormatter: $moneyFormatter,
            );
            $evidenceDueBy = self::formattedUnixDate($dispute['evidenceDueBy'] ?? null);
            $items[] = $evidenceDueBy === null
                ? $amount
                : self::template('orders.disputeAttention.itemWithDeadline', [
                    'amount' => $amount ?? '',
                    'deadline' => $evidenceDueBy,
                ]);
        }

        return $items === [] ? '' : self::template('orders.disputeAttention.requiresResponse', [
            'disputes' => implode(', ', $items),
        ]);
    }

    private function stripeCheckoutSessionId(): ?string
    {
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return null;
        }

        $data = $this->orderData($model);
        /** @var string|null $stripeCheckoutSessionId The stored order has already crossed the Order schema boundary. */
        $stripeCheckoutSessionId = $data['stripeCheckoutSessionId'] ?? null;

        return $stripeCheckoutSessionId;
    }

    private function hasRetryableLifecycleDelivery(): bool
    {
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return false;
        }

        $data = $this->orderData($model);
        /** @var list<array<string, mixed>> $lifecycleDeliveries */
        $lifecycleDeliveries = $data['lifecycleDeliveries'];
        $attemptedAt = new DateTimeImmutable();

        foreach ($lifecycleDeliveries as $lifecycleDelivery) {
            if (HookDeliveryLedger::isFailedRetryEligible($lifecycleDelivery, $attemptedAt)) {
                return true;
            }
        }

        return false;
    }

    private function isManuallyDeletable(): bool
    {
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return false;
        }

        return RetentionPolicy::isManuallyEligible(
            data: $this->orderData($model),
            now: new DateTimeImmutable(),
        );
    }

    public static function handle(string $name): string
    {
        return self::PREFIX . ucfirst($name);
    }

    public static function name(string $handle): ?string
    {
        if (str_starts_with($handle, self::PREFIX) === false) {
            return null;
        }

        return lcfirst(substr($handle, strlen(self::PREFIX)));
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private static function projection(
        array $order,
        string $panelLocale,
        MoneyFormatter $moneyFormatter,
    ): array {
        $customer = self::map($order['customer'] ?? null);
        $customerTaxIds = self::list($customer['taxIds'] ?? null);
        $billingTaxId = self::map($customerTaxIds[0] ?? null);
        $consent = self::map($order['consent'] ?? null);
        $termsOfServiceConsent = self::termsOfServiceConsent($consent['termsOfService'] ?? null);
        $promotionalEmailConsent = self::promotionalEmailConsent($consent['promotions'] ?? null);
        $billingAddress = self::map($order['billingAddress'] ?? null);
        $shippingAddress = self::map($order['shippingAddress'] ?? null);
        $shipping = self::map($order['shipping'] ?? null);
        $deliveryEstimate = self::map($shipping['deliveryEstimate'] ?? null);
        $discounts = self::list($order['discounts'] ?? null);
        $refunds = self::list($order['refunds'] ?? null);
        $disputes = self::list($order['disputes'] ?? null);
        $payment = self::map($order['payment'] ?? null);
        $checkoutCustomFields = self::checkoutCustomFields($order['customFields'] ?? null);
        $checkoutAttempt = self::map($order['checkoutAttempt'] ?? null);
        $providerFailure = self::map($checkoutAttempt['providerFailure'] ?? null);
        $requiresShipping = self::requiresShipping($order);
        $hasBillingAddress = array_filter(
            $billingAddress,
            static fn(mixed $value): bool => $value !== null && $value !== '',
        ) !== [];
        $billingTaxIdValue = $billingTaxId['value'] ?? null;
        $hasBillingTaxId = $billingTaxIdValue !== null && $billingTaxIdValue !== '';
        $hasBillingInformation = $hasBillingAddress || $hasBillingTaxId;
        $hasAdditionalInformation = $checkoutCustomFields !== []
            || $termsOfServiceConsent !== null
            || $promotionalEmailConsent !== null;
        $currency = OrderData::string($order['currency'] ?? null);
        $paymentCurrency = OrderData::nullableString($payment['currency'] ?? null) ?? $currency;
        $paymentAmount = OrderData::nullableString($payment['amount'] ?? null);
        $paymentAmountReceived = OrderData::nullableString($payment['amountReceived'] ?? null);
        $paymentAmountCaptured = OrderData::nullableString($payment['amountCaptured'] ?? null);
        $paymentMethod = self::paymentMethodLabel($payment['methodType'] ?? null);
        $failureMessage = OrderData::nullableString($payment['failureMessage'] ?? null);
        $paymentFailureMessage = $failureMessage === null || $failureMessage === ''
            ? self::translate('orders.paymentFailures.notProvided')
            : $failureMessage;
        $refundedTotal = OrderData::nullableString($order['refundedTotal'] ?? null);

        $values = [
            self::handle('hasDiscounts') => $discounts !== [],
            self::handle('hasAdditionalInformation') => $hasAdditionalInformation,
            self::handle('hasBillingAddress') => $hasBillingAddress,
            self::handle('hasBillingInformation') => $hasBillingInformation,
            self::handle('hasBillingTaxId') => $hasBillingTaxId,
            self::handle('hasCheckoutCustomFields') => $checkoutCustomFields !== [],
            self::handle('hasRefundedAmount') => $refundedTotal !== null
                && BigDecimal::of($refundedTotal)->isZero() === false,
            self::handle('hasTermsOfServiceConsent') => $termsOfServiceConsent !== null,
            self::handle('hasPromotionalEmailConsent') => $promotionalEmailConsent !== null,
            self::handle('hasPaymentAmount') => $paymentAmount !== null,
            self::handle('hasPaymentAmountReceived') => $paymentAmountReceived !== null,
            self::handle('hasPaymentAmountCaptured') => $paymentAmountCaptured !== null,
            self::handle('hasPaymentMethod') => $paymentMethod !== null,
            self::handle('hasRefunds') => $refunds !== [],
            self::handle('hasDisputes') => $disputes !== [],
            self::handle('requiresShipping') => $requiresShipping,
            self::handle('customerSummary') => $customer,
            self::handle('termsOfServiceConsent') => $termsOfServiceConsent,
            self::handle('promotionalEmailConsent') => $promotionalEmailConsent,
            self::handle('billingAddressSummary') => $billingAddress,
            self::handle('billingTaxIdType') => $billingTaxId['type'] ?? null,
            self::handle('billingTaxId') => $billingTaxId['value'] ?? null,
            self::handle('shippingAddressSummary') => $shippingAddress,
            self::handle('shippingMethod') => $shipping['label'] ?? null,
            self::handle('deliveryEstimate') => self::deliveryEstimate($deliveryEstimate),
            self::handle('formattedSubtotal') => self::formattedAmount($order['subtotal'] ?? null, $currency, $panelLocale, $moneyFormatter),
            self::handle('formattedDiscountTotal') => self::formattedAmount($order['discountTotal'] ?? null, $currency, $panelLocale, $moneyFormatter),
            self::handle('formattedShippingTotal') => self::formattedAmount($order['shippingTotal'] ?? null, $currency, $panelLocale, $moneyFormatter),
            self::handle('formattedTaxTotal') => self::formattedAmount($order['taxTotal'] ?? null, $currency, $panelLocale, $moneyFormatter),
            self::handle('formattedTotal') => self::formattedAmount($order['total'] ?? null, $currency, $panelLocale, $moneyFormatter),
            self::handle('formattedRefundedTotal') => self::formattedAmount($refundedTotal, $currency, $panelLocale, $moneyFormatter),
            self::handle('formattedCreatedAt') => self::formattedDate($order['createdAt'] ?? null),
            self::handle('formattedCheckoutCompletedAt') => self::formattedDate($order['checkoutCompletedAt'] ?? null),
            self::handle('formattedPaidAt') => self::formattedDate($order['paidAt'] ?? null),
            self::handle('formattedPaymentFailedAt') => self::formattedDate($order['paymentFailedAt'] ?? null),
            self::handle('formattedPaymentAmount') => self::formattedAmount($paymentAmount, $paymentCurrency, $panelLocale, $moneyFormatter),
            self::handle('formattedPaymentAmountReceived') => self::formattedAmount($paymentAmountReceived, $paymentCurrency, $panelLocale, $moneyFormatter),
            self::handle('formattedPaymentAmountCaptured') => self::formattedAmount($paymentAmountCaptured, $paymentCurrency, $panelLocale, $moneyFormatter),
            self::handle('paymentMethod') => $paymentMethod,
            self::handle('paymentFailureMessage') => $paymentFailureMessage,
            self::handle('paymentPaidAt') => self::formattedDate($order['paidAt'] ?? null),
            self::handle('paymentFailedAt') => self::formattedDate($order['paymentFailedAt'] ?? null),
            self::handle('refundSummaries') => self::refundSummaries(
                refunds: $refunds,
                panelLocale: $panelLocale,
                moneyFormatter: $moneyFormatter,
            ),
            self::handle('disputeSummaries') => self::disputeSummaries(
                disputes: $disputes,
                panelLocale: $panelLocale,
                moneyFormatter: $moneyFormatter,
            ),
            self::handle('orderItems') => self::orderItems(
                order: $order,
                panelLocale: $panelLocale,
                moneyFormatter: $moneyFormatter,
            ),
            self::handle('orderDiscounts') => self::orderDiscounts(
                discounts: $discounts,
                panelLocale: $panelLocale,
                moneyFormatter: $moneyFormatter,
            ),
            self::handle('checkoutCustomFields') => $checkoutCustomFields,
            self::handle('checkoutSessionRequest') => self::facts($checkoutAttempt['sessionRequest'] ?? null),
            self::handle('checkoutFailureType') => $providerFailure['type'] ?? null,
            self::handle('checkoutFailureRequestId') => $providerFailure['requestId'] ?? null,
            self::handle('checkoutFailureProviderCode') => $providerFailure['providerCode'] ?? null,
            self::handle('checkoutFailureProviderType') => $providerFailure['providerType'] ?? null,
            self::handle('checkoutFailureRetryable') => $providerFailure['retryable'] ?? null,
            self::handle('checkoutFailureOccurredAt') => $providerFailure['occurredAt'] ?? null,
            'initiatingLineItems' => self::initiatingLineItems($order['initiatingLineItems'] ?? null),
            'lifecycleDeliveries' => self::lifecycleDeliveries($order['lifecycleDeliveries'] ?? null),
        ];

        foreach (self::CHECKOUT_ATTEMPT_FIELDS as $field) {
            $values[self::handle('checkoutAttempt' . ucfirst($field))] = $checkoutAttempt[$field] ?? null;
        }

        $normalized = [];

        // Kirby normalizes form field handles before matching view values.
        foreach ($values as $field => $value) {
            $normalized[strtolower($field)] = $value;
        }

        return $normalized;
    }

    // Stripe uses separate outcome vocabularies for terms and promotional consent.
    // https://docs.stripe.com/api/checkout/sessions/object#checkout_session_object-consent
    private static function termsOfServiceConsent(mixed $value): ?string
    {
        $value = OrderData::nullableString($value);

        return $value === 'accepted'
            ? self::translate('orders.consent.accepted')
            : $value;
    }

    private static function promotionalEmailConsent(mixed $value): ?string
    {
        $value = OrderData::nullableString($value);

        return match ($value) {
            'opt_in' => self::translate('orders.consent.optedIn'),
            'opt_out' => self::translate('orders.consent.optedOut'),
            default => $value,
        };
    }

    private static function paymentMethodLabel(mixed $value): ?string
    {
        $value = OrderData::nullableString($value);

        // Preserve unknown Stripe method types instead of hiding future provider values.
        return match ($value) {
            'card' => self::translate('orders.paymentMethods.card'),
            'sepa_debit' => self::translate('orders.paymentMethods.sepaDebit'),
            default => $value,
        };
    }

    /** @return array{label: string, value: string, icon: string, theme: string} */
    private static function statusReport(string $name, string $value, string $theme): array
    {
        return [
            'label' => $value,
            'value' => self::translate('orders.fields.' . $name),
            'icon' => match ($theme) {
                'positive' => 'check',
                'negative', 'notice' => 'alert',
                default => 'info',
            },
            'theme' => $theme,
        ];
    }

    /** @param array<string, mixed> $order */
    private static function stripeDashboardUrlFor(array $order): ?string
    {
        $stripeCheckoutSessionId = OrderData::nullableString($order['stripeCheckoutSessionId'] ?? null);
        $checkoutAttempt = OrderData::map($order['checkoutAttempt'] ?? null);
        $credentialMode = CredentialMode::from(OrderData::string($checkoutAttempt['credentialMode'] ?? null));

        if ($stripeCheckoutSessionId === null || $credentialMode === CredentialMode::Unknown) {
            return null;
        }

        // Stripe Dashboard deep links insert /test for sandbox resources.
        // https://docs.stripe.com/stripe-apps/deep-links
        $modePath = $credentialMode === CredentialMode::Test ? 'test/' : '';

        return 'https://dashboard.stripe.com/' . $modePath . 'checkout/sessions/' . rawurlencode($stripeCheckoutSessionId);
    }

    private static function translate(string $suffix): string
    {
        $translation = I18n::translate(Catalogue::PREFIX . $suffix);

        return is_string($translation) ? $translation : $suffix;
    }

    /** @param array<string, string> $values */
    private static function template(string $suffix, array $values): string
    {
        return I18n::template(Catalogue::PREFIX . $suffix, $values);
    }

    private function panelLocale(): string
    {
        $kirby = $this->model->kirby();
        $panelLanguage = $kirby->user()?->language() ?? $kirby->panelLanguage();

        return $kirby->translation($panelLanguage)->locale();
    }

    private static function formattedDate(mixed $value): ?string
    {
        $value = OrderData::nullableString($value);

        if ($value === null) {
            return null;
        }

        return self::formattedDateTime(new DateTimeImmutable($value));
    }

    private static function formattedUnixDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::formattedDateTime(
            (new DateTimeImmutable())->setTimestamp(OrderData::integer($value)),
        );
    }

    private static function formattedDateTime(DateTimeImmutable $date): string
    {
        $timezoneName = date_default_timezone_get();
        $date = $date->setTimezone(new DateTimeZone($timezoneName));

        return $date->format('Y-m-d H:i:s');
    }

    private static function formattedAmount(
        mixed $value,
        string $currency,
        string $panelLocale,
        MoneyFormatter $moneyFormatter,
    ): ?string {
        $value = OrderData::nullableString($value);

        return $value === null ? null : $moneyFormatter->format($value, $currency, $panelLocale);
    }

    private function refundNotice(string $status, string $translationKey, ?string $reasonField = null): string
    {
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return '';
        }

        $items = [];
        $moneyFormatter = new MoneyFormatter($model->kirby());

        foreach (self::list($this->orderData($model)['refunds'] ?? null) as $value) {
            $refund = self::map($value);

            if (($refund['status'] ?? null) !== $status) {
                continue;
            }

            $amount = self::formattedAmount(
                value: $refund['amount'] ?? null,
                currency: OrderData::string($refund['currency'] ?? null),
                panelLocale: $this->panelLocale(),
                moneyFormatter: $moneyFormatter,
            );
            $reason = $reasonField === null
                ? null
                : OrderData::nullableString($refund[$reasonField] ?? null);
            $reason = $reason === null
                ? null
                : self::providerLabel('refundReasons', $reason);
            $items[] = $reason === null
                ? $amount
                : self::template('orders.refundAttention.itemWithReason', [
                    'amount' => $amount ?? '',
                    'reason' => $reason,
                ]);
        }

        return $items === [] ? '' : self::template($translationKey, [
            'refunds' => implode(', ', $items),
        ]);
    }

    /** @return array<string, mixed> */
    private function orderData(OrderPage $model): array
    {
        return $this->orderData ??= (new OrderPageStore($model->kirby()))->data($model);
    }

    /** @return list<array<string, mixed>> */
    private static function initiatingLineItems(mixed $value): array
    {
        $items = [];

        foreach (self::list($value) as $item) {
            $item = self::map($item);
            $providerAmounts = self::map($item['providerAmounts'] ?? null);
            $items[] = [
                ...$item,
                'metadata' => self::facts($item['metadata'] ?? null),
                'providerPrice' => $providerAmounts['price'] ?? null,
                'providerSubtotal' => $providerAmounts['subtotal'] ?? null,
            ];
            unset($items[array_key_last($items)]['providerAmounts']);
        }

        return $items;
    }

    /** @param array<string, mixed> $order */
    private static function requiresShipping(array $order): bool
    {
        // The frozen store input owns this fact; Stripe's final line items do not reproduce it.
        foreach (self::list($order['initiatingLineItems'] ?? null) as $value) {
            $lineItem = self::map($value);

            if (($lineItem['requiresShipping'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $deliveryEstimate */
    private static function deliveryEstimate(array $deliveryEstimate): ?string
    {
        if ($deliveryEstimate === []) {
            return null;
        }

        /** @var int|null $minimum */
        $minimum = $deliveryEstimate['minimum'];
        /** @var int|null $maximum */
        $maximum = $deliveryEstimate['maximum'];
        /** @var string $unit */
        $unit = $deliveryEstimate['unit'];
        $range = match (true) {
            $minimum === null => (string) $maximum,
            $maximum === null, $minimum === $maximum => (string) $minimum,
            default => $minimum . '–' . $maximum,
        };
        $count = $maximum ?? $minimum;
        $unitLabel = self::translate('orders.deliveryUnits.' . $unit . '.' . ($count === 1 ? 'one' : 'other'));

        return $range . ' ' . $unitLabel;
    }

    /**
     * Stripe can return line items before Checkout completes. Until completion,
     * the Panel presents the frozen initiating items instead of implying that
     * provider amounts are final.
     *
     * @param array<string, mixed> $order
     * @return list<array<string, mixed>>
     */
    private static function orderItems(
        array $order,
        string $panelLocale,
        MoneyFormatter $moneyFormatter,
    ): array {
        $initiatingLineItems = self::list($order['initiatingLineItems'] ?? null);
        $lineItems = self::list($order['lineItems'] ?? null);
        $checkoutStatus = CheckoutStatus::from(OrderData::string($order['checkoutStatus'] ?? null));

        if ($checkoutStatus !== CheckoutStatus::Complete || $lineItems === []) {
            return array_map(static function (mixed $value) use ($panelLocale, $moneyFormatter): array {
                $initiatingLineItem = self::map($value);

                return self::orderItem(
                    initiatingLineItem: $initiatingLineItem,
                    lineItem: null,
                    panelLocale: $panelLocale,
                    moneyFormatter: $moneyFormatter,
                );
            }, $initiatingLineItems);
        }

        return array_map(static function (mixed $value) use (
            $initiatingLineItems,
            $panelLocale,
            $moneyFormatter,
        ): array {
            $lineItem = self::map($value);
            /** @var int $initiatingIndex The stored order has already crossed the Order schema boundary. */
            $initiatingIndex = $lineItem['initiatingIndex'];

            return self::orderItem(
                initiatingLineItem: self::map($initiatingLineItems[$initiatingIndex]),
                lineItem: $lineItem,
                panelLocale: $panelLocale,
                moneyFormatter: $moneyFormatter,
            );
        }, $lineItems);
    }

    /**
     * @param array<string, mixed> $initiatingLineItem
     * @param array<string, mixed>|null $lineItem
     * @return array<string, mixed>
     */
    private static function orderItem(
        array $initiatingLineItem,
        ?array $lineItem,
        string $panelLocale,
        MoneyFormatter $moneyFormatter,
    ): array {
        $options = self::list($initiatingLineItem['options'] ?? null);
        $optionLabels = array_map(static function (mixed $value): string {
            $option = self::map($value);
            /** @var string $optionName */
            $optionName = $option['optionName'];
            /** @var string $valueName */
            $valueName = $option['valueName'];

            return $optionName . ': ' . $valueName;
        }, $options);
        $amounts = $lineItem ?? $initiatingLineItem;
        $currency = OrderData::string($amounts['currency'] ?? null);

        return [
            'name' => $initiatingLineItem['name'] ?? $lineItem['description'] ?? null,
            'description' => $initiatingLineItem['description'] ?? null,
            'optionSummary' => $optionLabels,
            'sku' => $initiatingLineItem['sku'] ?? null,
            'quantity' => $amounts['quantity'] ?? null,
            'requiresShipping' => $initiatingLineItem['requiresShipping'] ?? null,
            'currency' => $currency,
            'price' => self::formattedAmount($amounts['price'] ?? null, $currency, $panelLocale, $moneyFormatter),
            'subtotal' => self::formattedAmount($amounts['subtotal'] ?? null, $currency, $panelLocale, $moneyFormatter),
            'discount' => self::formattedAmount($lineItem['discount'] ?? null, $currency, $panelLocale, $moneyFormatter),
            'tax' => self::formattedAmount($lineItem['tax'] ?? null, $currency, $panelLocale, $moneyFormatter),
            'total' => self::formattedAmount(
                $lineItem['total'] ?? $initiatingLineItem['subtotal'] ?? null,
                $currency,
                $panelLocale,
                $moneyFormatter,
            ),
        ];
    }

    /**
     * @param list<mixed> $discounts
     * @return list<array<string, mixed>>
     */
    private static function orderDiscounts(
        array $discounts,
        string $panelLocale,
        MoneyFormatter $moneyFormatter,
    ): array {
        return array_map(static function (mixed $value) use ($panelLocale, $moneyFormatter): array {
            $discount = self::map($value);
            $currency = OrderData::string($discount['currency'] ?? null);
            $percentOff = OrderData::nullableString($discount['percentOff'] ?? null);

            return [
                'couponName' => $discount['couponName'] ?? null,
                'promotionCode' => $discount['promotionCode'] ?? null,
                'amount' => self::formattedAmount($discount['amount'] ?? null, $currency, $panelLocale, $moneyFormatter),
                'percentOff' => $percentOff === null ? null : $percentOff . '%',
            ];
        }, $discounts);
    }

    /**
     * @param list<mixed> $refunds
     * @return list<array<string, mixed>>
     */
    private static function refundSummaries(
        array $refunds,
        string $panelLocale,
        MoneyFormatter $moneyFormatter,
    ): array {
        return array_map(static function (mixed $value) use ($panelLocale, $moneyFormatter): array {
            $refund = self::map($value);
            $currency = OrderData::string($refund['currency'] ?? null);
            $reason = self::refundReason($refund);

            return [
                'amount' => self::formattedAmount($refund['amount'] ?? null, $currency, $panelLocale, $moneyFormatter),
                'status' => self::providerLabel('refundStatuses', OrderData::string($refund['status'] ?? null)),
                'reason' => $reason === null ? '–' : self::providerLabel('refundReasons', $reason),
                'createdAt' => self::formattedUnixDate($refund['createdAt'] ?? null),
                'updatedAt' => self::formattedDate($refund['updatedAt'] ?? null),
            ];
        }, $refunds);
    }

    /**
     * @param list<mixed> $disputes
     * @return list<array<string, mixed>>
     */
    private static function disputeSummaries(
        array $disputes,
        string $panelLocale,
        MoneyFormatter $moneyFormatter,
    ): array {
        return array_map(static function (mixed $value) use ($panelLocale, $moneyFormatter): array {
            $dispute = self::map($value);
            $currency = OrderData::string($dispute['currency'] ?? null);

            return [
                'amount' => self::formattedAmount($dispute['amount'] ?? null, $currency, $panelLocale, $moneyFormatter),
                'status' => self::providerLabel('disputeStatuses', OrderData::string($dispute['status'] ?? null)),
                'reason' => self::providerLabel('disputeReasons', OrderData::string($dispute['reason'] ?? null)),
                'evidenceDueBy' => self::formattedUnixDate($dispute['evidenceDueBy'] ?? null) ?? '–',
                'createdAt' => self::formattedUnixDate($dispute['createdAt'] ?? null),
                'updatedAt' => self::formattedDate($dispute['updatedAt'] ?? null),
            ];
        }, $disputes);
    }

    /** @param array<string, mixed> $refund */
    private static function refundReason(array $refund): ?string
    {
        foreach (['failureReason', 'pendingReason', 'reason'] as $field) {
            $reason = OrderData::nullableString($refund[$field] ?? null);

            if ($reason !== null && $reason !== '') {
                return $reason;
            }
        }

        return null;
    }

    private static function providerLabel(string $group, string $value): string
    {
        if ($value === '') {
            return '–';
        }

        $translationKey = 'orders.' . $group . '.' . $value;
        $translation = self::translate($translationKey);

        // Provider vocabularies can grow before the plugin adds a dedicated label.
        return $translation === $translationKey ? $value : $translation;
    }

    /** @return list<array{label: mixed, value: mixed}> */
    private static function checkoutCustomFields(mixed $value): array
    {
        $checkoutCustomFields = [];

        foreach (self::list($value) as $entry) {
            $customField = self::map($entry);
            // Keep the frozen Checkout field set visible; a dash distinguishes
            // an unanswered field from one that was never offered for this Order.
            $checkoutCustomFields[] = [
                'label' => $customField['label'] ?? null,
                'value' => ($customField['answered'] ?? false) === true
                    ? $customField['value'] ?? null
                    : '–',
            ];
        }

        return $checkoutCustomFields;
    }

    /** @return list<array<string, mixed>> */
    private static function lifecycleDeliveries(mixed $value): array
    {
        $deliveries = [];

        foreach (self::list($value) as $delivery) {
            $delivery = self::map($delivery);
            $event = self::map($delivery['event'] ?? null);
            $payloadRetained = array_key_exists('orderSnapshot', $event);
            $nextActionType = self::nextActionType($event['nextAction'] ?? null);
            unset($event['orderSnapshot'], $event['nextAction']);

            // Payload retention exists so failed hooks can replay the original event.
            // Those payloads can contain customer snapshots and private payment handoff data,
            // so orders.read exposes only their availability and safe action type.
            $deliveries[] = [
                ...$delivery,
                ...$event,
                'payloadRetained' => $payloadRetained,
                'nextActionType' => $nextActionType,
            ];
            unset($deliveries[array_key_last($deliveries)]['event']);
        }

        return $deliveries;
    }

    /**
     * Flattens arbitrary provider facts into homogeneous rows that Kirby's
     * native Structure field can display without exposing a raw data dump.
     *
     * @return list<array{path: string, value: mixed}>
     */
    private static function facts(mixed $value, string $path = ''): array
    {
        if (is_array($value)) {
            if ($value === []) {
                return $path === '' ? [] : [['path' => $path, 'value' => null]];
            }

            $facts = [];

            foreach ($value as $key => $entry) {
                $entryPath = $path === ''
                    ? (string) $key
                    : $path . (is_int($key) ? '[' . $key . ']' : '.' . $key);
                $facts = [...$facts, ...self::facts($entry, $entryPath)];
            }

            return $facts;
        }

        return [['path' => $path, 'value' => is_bool($value) ? ($value ? 'true' : 'false') : $value]];
    }

    private static function nextActionType(mixed $value): ?string
    {
        return is_string($value) ? PaymentAction::fromJson($value)->type() : null;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (is_array($value) === false || array_is_list($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $map[$key] = $entry;
            }
        }

        return $map;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
