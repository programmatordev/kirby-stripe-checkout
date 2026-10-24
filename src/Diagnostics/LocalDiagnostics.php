<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Diagnostics;

use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Uuid\Uuid;
use Kirby\Uuid\Uuids;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationErrorCode;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Configuration\Setting;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Housekeeping\Exception\HousekeepingException;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingRunStatus;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingStateStore;
use ProgrammatorDev\StripeCheckout\Kirby\OrderHookDispatcher;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\WebhookRoutes;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderUuidValidator;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use Stripe\Stripe;

/**
 * Reports only local, non-sensitive integration facts without Stripe traffic.
 *
 * @internal
 */
final class LocalDiagnostics
{
    private readonly DateTimeImmutable $checkedAt;

    public function __construct(
        private readonly App $kirby,
        ?DateTimeImmutable $checkedAt = null,
    ) {
        $this->checkedAt = $checkedAt ?? new DateTimeImmutable();
    }

    public function report(): DiagnosticReport
    {
        $checks = [
            $this->dependency('php', PHP_VERSION, version_compare(PHP_VERSION, '8.2.0', '>=')),
            $this->dependency('kirby', App::version(), version_compare((string) App::version(), '5.6.0', '>=')),
            $this->dependency('stripePhp', defined(Stripe::class . '::VERSION') ? Stripe::VERSION : null, class_exists(Stripe::class)),
            $this->dependency('intl', defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : null, extension_loaded('intl')),
            $this->orderIdentity(),
            $this->webhookRoute(),
            $this->storageWriteAccess(),
        ];

        $runtime = new RuntimeFactory($this->kirby);
        $configurationReport = $runtime->configurationReport();
        $retentionPolicy = null;

        if ($configurationReport->isValid() === false) {
            $error = $configurationReport->error();
            $checks[] = $this->failure(
                code: 'configuration',
                error: $error,
                affectedOperations: ['checkout', 'webhook', 'order_sync', 'housekeeping', 'remote_diagnostics'],
            );

            foreach (['secretKey', 'publishableKey', 'webhookSecret'] as $id) {
                $checks[] = $this->check($id, DiagnosticStatus::NotVerifiable, 'credential.unknown');
            }

            foreach (['currency', 'defaultRequiresShipping'] as $id) {
                $checks[] = $this->check($id, DiagnosticStatus::NotVerifiable, 'setting.unknown');
            }

            $checks[] = $this->check('shippingResolver', DiagnosticStatus::NotVerifiable, 'shippingResolver.unknown');
        } else {
            $checks[] = $this->check('configuration', DiagnosticStatus::Pass, 'configuration.ready');
            $configuration = $configurationReport->configurationOrFail();
            $stripe = $configuration->stripe();
            $settings = $configuration->settings();
            $retentionPolicy = new RetentionPolicy($settings);
            $checks[] = $this->settingSources($settings->all());

            $checks[] = $configuration->shipping()->resolver() === null
                ? $this->check('shippingResolver', DiagnosticStatus::Pass, 'shippingResolver.zones')
                : $this->check('shippingResolver', DiagnosticStatus::Pass, 'shippingResolver.custom');

            if ($settings->automaticTax() && $settings->priceSource() === PriceSource::Kirby) {
                $checks[] = $this->taxCodes($runtime);
            }

            if ($settings->priceSource() === PriceSource::Stripe) {
                $checks[] = $this->prices($runtime, $settings->currency());
            }

            $checks[] = $this->credential(
                code: 'secretKey',
                configured: $stripe->hasSecretKey(),
                mode: $stripe->secretKeyMode(),
                affectedOperations: ['checkout', 'webhook', 'order_sync', 'housekeeping', 'remote_diagnostics'],
            );
            $checks[] = $settings->uiMode() === UiMode::Embedded || $stripe->hasPublishableKey()
                ? $this->credential(
                    code: 'publishableKey',
                    configured: $stripe->hasPublishableKey(),
                    mode: $stripe->publishableKeyMode(),
                    affectedOperations: ['checkout'],
                )
                : $this->check('publishableKey', DiagnosticStatus::Pass, 'credential.notRequired');
            $checks[] = $this->credential(
                code: 'webhookSecret',
                configured: $stripe->hasWebhookSecret(),
                mode: CredentialMode::Unknown,
                affectedOperations: ['webhook'],
            );
            $housekeeping = $configuration->housekeeping();
            $checks[] = $this->check('housekeeping', DiagnosticStatus::Pass, 'housekeeping.configured', [
                'batchSize' => (string) $housekeeping->batchSize(),
                'lifecycleDeliveryPayloadRetentionDays' => (string) $housekeeping->lifecycleDeliveryPayloadRetentionDays(),
            ]);
            $checks[] = $this->check('retention', DiagnosticStatus::Pass, 'retention.configured', [
                'incompleteOrderRetentionDays' => (string) $settings->incompleteOrderRetentionDays(),
                'unpaidOrderRetentionDays' => (string) $settings->unpaidOrderRetentionDays(),
            ]);
            $checks[] = $settings->currency() === null
                ? $this->check('currency', DiagnosticStatus::Warning, 'setting.missing', affectedOperations: ['checkout'])
                : $this->check('currency', DiagnosticStatus::Pass, 'setting.ready');
            $checks[] = $settings->defaultRequiresShipping() === null
                ? $this->check('defaultRequiresShipping', DiagnosticStatus::Warning, 'setting.missing')
                : $this->check('defaultRequiresShipping', DiagnosticStatus::Pass, 'setting.ready');
        }

        try {
            $hubPage = (new StripeCheckoutPageStore($this->kirby))->page();
            $checks[] = $hubPage === null
                ? $this->check('hubPage', DiagnosticStatus::Warning, 'hubPage.missing')
                : $this->check('hubPage', DiagnosticStatus::Pass, 'hubPage.ready');
        } catch (ConfigurationException $error) {
            $checks[] = $this->failure(
                code: 'hubPage',
                error: $error,
                messageKey: 'hubPage.invalid',
                affectedOperations: ['panel'],
            );
        }

        $checks = [...$checks, ...$this->orderChecks($retentionPolicy)];

        if ((new OrderHookDispatcher($this->kirby))->hasFailedDeletionDelivery()) {
            $checks[] = $this->check('lifecycleDeletion', DiagnosticStatus::Warning, 'lifecycleDeletion.failed');
        }

        $checks[] = $this->housekeepingRun();

        return new DiagnosticReport($this->checkedAt, $checks);
    }

    /** @return list<DiagnosticCheck> */
    private function orderChecks(?RetentionPolicy $retentionPolicy): array
    {
        try {
            $store = new OrderPageStore($this->kirby);
            $container = $store->container();
            $orders = $store->orders();
            $validCount = $orders->count();
            $invalidCount = ($container?->childrenAndDrafts()->count() ?? 0) - $validCount;
            $orderCheck = match (true) {
                $container === null => $this->check('orders', DiagnosticStatus::Warning, 'orders.missing'),
                $invalidCount > 0 => $this->check(
                    code: 'orders',
                    status: DiagnosticStatus::Fail,
                    messageKey: 'orders.invalidChildren',
                    context: ['count' => (string) $invalidCount],
                    affectedOperations: ['webhook', 'order_sync', 'housekeeping'],
                ),
                default => $this->check('orders', DiagnosticStatus::Pass, 'orders.ready'),
            };
            $pendingCount = 0;
            $failedCount = 0;
            $pendingEventCount = 0;
            $failedEventCount = 0;
            $pendingPaymentCount = 0;
            $oldestPendingPaymentAt = null;
            $eligibleHousekeepingCount = 0;
            $recoveryDueCount = 0;

            foreach ($orders as $order) {
                // Re-read the current snapshot because an Order can change after the validated Page enumeration.
                $data = $store->data($order);

                foreach (OrderData::list($data['lifecycleDeliveries'] ?? []) as $entry) {
                    $entry = OrderData::map($entry);
                    $pendingCount += $entry['status'] === 'pending' ? 1 : 0;
                    $failedCount += $entry['status'] === 'failed' ? 1 : 0;
                }

                foreach (OrderData::list($data['events'] ?? []) as $entry) {
                    $entry = OrderData::map($entry);
                    $pendingEventCount += $entry['status'] === 'pending' ? 1 : 0;
                    $failedEventCount += $entry['status'] === 'failed' ? 1 : 0;
                }

                if ($data['paymentStatus'] === PaymentStatus::Pending->value) {
                    $pendingPaymentCount++;
                    $completedAt = OrderData::date($data['checkoutCompletedAt']);
                    $oldestPendingPaymentAt = $oldestPendingPaymentAt === null || $completedAt < $oldestPendingPaymentAt
                        ? $completedAt
                        : $oldestPendingPaymentAt;
                }

                if ($retentionPolicy !== null) {
                    $eligibleHousekeepingCount += $retentionPolicy->isEligible($data, $this->checkedAt) ? 1 : 0;
                    $recoveryDueCount += $retentionPolicy->needsCheckoutRecovery($data, $this->checkedAt) ? 1 : 0;
                }
            }

            $checks = [
                $orderCheck,
                $this->check('lifecycle', $pendingCount + $failedCount > 0 ? DiagnosticStatus::Warning : DiagnosticStatus::Pass, 'lifecycle.summary', [
                    'pending' => (string) $pendingCount,
                    'failed' => (string) $failedCount,
                ]),
                $this->check('events', $pendingEventCount + $failedEventCount > 0 ? DiagnosticStatus::Warning : DiagnosticStatus::Pass, 'events.summary', [
                    'pending' => (string) $pendingEventCount,
                    'failed' => (string) $failedEventCount,
                ]),
                // Pending is a normal asynchronous payment state; expose its age without classifying it as a failure.
                $this->check('pendingPayments', DiagnosticStatus::Pass, 'pendingPayments.summary', [
                    'count' => (string) $pendingPaymentCount,
                    'oldestAt' => $oldestPendingPaymentAt === null ? '—' : OrderData::timestamp($oldestPendingPaymentAt),
                    'oldestAgeSeconds' => $oldestPendingPaymentAt === null
                        ? '0'
                        : (string) max(0, $this->checkedAt->getTimestamp() - $oldestPendingPaymentAt->getTimestamp()),
                ]),
            ];
            $checks[] = $retentionPolicy === null
                ? $this->check('housekeepingEligibility', DiagnosticStatus::NotVerifiable, 'housekeepingEligibility.unknown')
                : $this->check(
                    code: 'housekeepingEligibility',
                    status: $eligibleHousekeepingCount + $recoveryDueCount > 0 ? DiagnosticStatus::Warning : DiagnosticStatus::Pass,
                    messageKey: 'housekeepingEligibility.summary',
                    context: [
                        'eligible' => (string) $eligibleHousekeepingCount,
                        'recoveryDue' => (string) $recoveryDueCount,
                    ],
                );

            return $checks;
        } catch (OrderStorageException|OrderQueryException $error) {
            return [$this->check(
                code: 'orders',
                status: DiagnosticStatus::Fail,
                messageKey: 'orders.invalid',
                context: ['code' => $error->errorCode()],
                affectedOperations: ['checkout', 'webhook', 'order_sync', 'housekeeping'],
            )];
        }
    }

    private function housekeepingRun(): DiagnosticCheck
    {
        try {
            $state = (new HousekeepingStateStore($this->kirby))->read();
        } catch (HousekeepingException $error) {
            return $this->check(
                code: 'housekeepingRun',
                status: DiagnosticStatus::Fail,
                messageKey: 'housekeepingRun.invalid',
                context: ['code' => $error->errorCode()],
                affectedOperations: ['housekeeping'],
            );
        }

        $lastAttempt = $state->lastAttempt();

        if ($lastAttempt === null) {
            return $this->check('housekeepingRun', DiagnosticStatus::Warning, 'housekeepingRun.never');
        }

        $lastSuccess = $state->lastSuccess();
        $context = [
            'attemptedAt' => OrderData::timestamp($lastAttempt->attemptedAt()),
            'completedAt' => OrderData::timestamp($lastAttempt->completedAt()),
            'status' => $lastAttempt->status()->value,
            'code' => $lastAttempt->errorCode() ?? '—',
            'lastSuccessAt' => $lastSuccess === null ? '—' : OrderData::timestamp($lastSuccess->completedAt()),
        ];

        return $lastAttempt->status() === HousekeepingRunStatus::Success
            ? $this->check('housekeepingRun', DiagnosticStatus::Pass, 'housekeepingRun.ready', $context)
            : $this->check('housekeepingRun', DiagnosticStatus::Warning, 'housekeepingRun.issue', $context);
    }

    private function dependency(string $code, ?string $version, bool $available): DiagnosticCheck
    {
        return $available === true && $version !== null
            ? $this->check($code, DiagnosticStatus::Pass, 'dependency.ready', ['version' => $version])
            : $this->check($code, DiagnosticStatus::Fail, 'dependency.missing');
    }

    private function webhookRoute(): DiagnosticCheck
    {
        $registered = false;

        foreach ($this->kirby->routes() as $route) {
            if (is_array($route) === false) {
                continue;
            }

            if (($route['pattern'] ?? null) === WebhookRoutes::PATH && ($route['method'] ?? null) === 'POST') {
                $registered = true;

                break;
            }
        }

        $url = WebhookRoutes::url($this->kirby);
        $parts = parse_url($url);
        $validUrl = $parts !== false
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true);

        return $registered && $validUrl
            ? $this->check('webhookRoute', DiagnosticStatus::Pass, 'webhookRoute.ready', ['url' => $url])
            : $this->check(
                code: 'webhookRoute',
                status: DiagnosticStatus::Fail,
                messageKey: 'webhookRoute.invalid',
                context: ['url' => $url],
                affectedOperations: ['webhook'],
            );
    }

    private function storageWriteAccess(): DiagnosticCheck
    {
        $contentRoot = $this->kirby->root('content');
        $siteRoot = $this->kirby->root('site');
        $contentWritable = $contentRoot !== null && is_writable($contentRoot);

        if ($siteRoot === null) {
            $storageWritable = false;
        } else {
            $storageRoot = $siteRoot . '/storage';
            // The first operational write creates the storage directory, so its parent owns writability until then.
            $storageWritable = is_dir($storageRoot)
                ? is_writable($storageRoot)
                : is_writable($siteRoot);
        }

        $context = [
            'content' => $contentWritable ? 'writable' : 'not_writable',
            'storage' => $storageWritable ? 'writable' : 'not_writable',
        ];

        return $contentWritable && $storageWritable
            ? $this->check('storageWriteAccess', DiagnosticStatus::Pass, 'storageWriteAccess.ready', $context)
            : $this->check(
                code: 'storageWriteAccess',
                status: DiagnosticStatus::Fail,
                messageKey: 'storageWriteAccess.unavailable',
                context: $context,
                affectedOperations: ['checkout', 'webhook', 'order_sync', 'housekeeping'],
            );
    }

    /** @param array<string, Setting> $settings */
    private function settingSources(array $settings): DiagnosticCheck
    {
        $sources = [
            'default' => 0,
            'page' => 0,
            'php' => 0,
            'shadowed' => 0,
        ];

        foreach ($settings as $setting) {
            $sources[$setting->source()->value]++;
            $sources['shadowed'] += $setting->hasShadowedValue() ? 1 : 0;
        }

        return $this->check('settingSources', DiagnosticStatus::Pass, 'settingSources.summary', array_map(
            static fn(int $count): string => (string) $count,
            $sources,
        ));
    }

    private function taxCodes(RuntimeFactory $runtime): DiagnosticCheck
    {
        if (PluginPermissions::allows($this->kirby, 'taxCodes.read') === false) {
            return $this->check('taxCodes', DiagnosticStatus::NotVerifiable, 'taxCodes.denied');
        }

        // These are cache facts, not Stripe Tax/account readiness checks.
        $state = $runtime->taxCodeCatalogue()->cached();
        $values = [
            'count' => (string) count($state->items()),
            'refreshedAt' => $state->refreshedAt() === null ? '—' : gmdate('Y-m-d H:i:s \U\T\C', $state->refreshedAt()),
            'failedAt' => $state->failedAt() === null ? '—' : gmdate('Y-m-d H:i:s \U\T\C', $state->failedAt()),
        ];

        return $this->check(
            'taxCodes',
            $state->refreshedAt() !== null && $state->error() === null ? DiagnosticStatus::Pass : DiagnosticStatus::Warning,
            $state->error() !== null ? 'taxCodes.failed' : ($state->refreshedAt() === null ? 'taxCodes.empty' : 'taxCodes.ready'),
            $values,
        );
    }

    private function prices(RuntimeFactory $runtime, ?string $currency): DiagnosticCheck
    {
        if (PluginPermissions::allows($this->kirby, 'prices.read') === false) {
            return $this->check('prices', DiagnosticStatus::NotVerifiable, 'prices.denied');
        }

        if ($currency === null) {
            return $this->check('prices', DiagnosticStatus::NotVerifiable, 'prices.currencyMissing');
        }

        // Read the existing catalogue snapshot without invoking its refresh policy.
        $state = $runtime->stripePriceCatalogue()->cached($currency);
        $context = [
            'count' => (string) count($state->items()),
            'refreshedAt' => $state->refreshedAt() === null ? '—' : gmdate('Y-m-d H:i:s \U\T\C', $state->refreshedAt()),
            'failedAt' => $state->failedAt() === null ? '—' : gmdate('Y-m-d H:i:s \U\T\C', $state->failedAt()),
        ];

        return $this->check(
            'prices',
            $state->refreshedAt() !== null && $state->error() === null ? DiagnosticStatus::Pass : DiagnosticStatus::Warning,
            $state->error() !== null ? 'prices.failed' : ($state->refreshedAt() === null ? 'prices.empty' : 'prices.ready'),
            $context,
        );
    }

    /** @param list<string> $affectedOperations */
    private function credential(
        string $code,
        bool $configured,
        CredentialMode $mode,
        array $affectedOperations,
    ): DiagnosticCheck {
        if ($configured === false) {
            return $this->check(
                code: $code,
                status: DiagnosticStatus::Warning,
                messageKey: 'credential.missing',
                affectedOperations: $affectedOperations,
            );
        }

        return $this->check(
            $code,
            DiagnosticStatus::Pass,
            'credential.configured',
            ['mode' => $mode->value],
        );
    }

    private function orderIdentity(): DiagnosticCheck
    {
        if (Uuids::enabled() === false) {
            return $this->check(
                code: 'orderIdentity',
                status: DiagnosticStatus::Fail,
                messageKey: 'orderIdentity.disabled',
                affectedOperations: ['checkout'],
            );
        }

        // Calling a project-defined generator could consume a sequence number or trigger other project work merely by opening Diagnostics.
        // Its concrete output is therefore checked only when Checkout issues a real token.
        if (Uuid::$generator !== null) {
            return $this->check('orderIdentity', DiagnosticStatus::NotVerifiable, 'orderIdentity.custom');
        }

        $option = $this->kirby->option('content.uuid');

        if (is_array($option)) {
            $option = $option['format'] ?? null;
        }

        $sample = $option === 'uuid-v4'
            ? '00000000-0000-4000-8000-000000000000'
            : str_repeat('a', 16);

        try {
            OrderUuidValidator::validate($sample);
        } catch (OrderDataException) {
            return $this->check(
                code: 'orderIdentity',
                status: DiagnosticStatus::Fail,
                messageKey: 'orderIdentity.incompatible',
                affectedOperations: ['checkout'],
            );
        }

        return $this->check('orderIdentity', DiagnosticStatus::Pass, 'orderIdentity.ready');
    }

    /** @param list<string> $affectedOperations */
    private function failure(
        string $code,
        ?ConfigurationException $error,
        string $messageKey = 'configuration.invalid',
        array $affectedOperations = [],
    ): DiagnosticCheck {
        return $this->check($code, DiagnosticStatus::Fail, $messageKey, [
            'code' => $error?->errorCode() ?? ConfigurationErrorCode::ROOT_INVALID,
            'path' => $error?->path() ?? 'programmatordev.stripe-checkout',
        ], $affectedOperations);
    }

    /**
     * @param array<string, string> $context
     * @param list<string> $affectedOperations
     */
    private function check(
        string $code,
        DiagnosticStatus $status,
        string $messageKey,
        array $context = [],
        array $affectedOperations = [],
    ): DiagnosticCheck {
        return new DiagnosticCheck(
            code: $code,
            status: $status,
            messageKey: $messageKey,
            context: $context,
            affectedOperations: $affectedOperations,
        );
    }
}
