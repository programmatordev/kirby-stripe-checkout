<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\Api;
use Kirby\Cms\App;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\FieldClass;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Money\MoneyFormatter;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Stripe\Price\ResolvedPrice;

/**
 * Exposes one scalar Stripe Price reference through Kirby's field API.
 *
 * @internal
 */
final class StripePriceField extends FieldClass
{
    private readonly bool $sourceInactive;

    /** @param array<string, mixed> $params */
    public function __construct(array $params = [])
    {
        parent::__construct($params);

        $this->sourceInactive = ($params['sourceInactive'] ?? false) === true;
    }

    public function emptyValue(): string
    {
        return '';
    }

    public function isDisabled(): bool
    {
        return parent::isDisabled() || PluginPermissions::allows($this->kirby(), 'prices.read') === false;
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        /** @var array<string, mixed> $props */
        $props = parent::props();
        $value = $this->toFormValue();

        if ($this->sourceInactive === true) {
            return [
                ...$props,
                'catalogue' => [
                    'error' => null,
                    'failedAt' => null,
                    'refreshedAt' => null,
                    'status' => 'empty',
                ],
                'currency' => null,
                'disabled' => true,
                'selected' => self::fallbackSelected($value, false),
                'sourceInactive' => true,
                'value' => $value,
            ];
        }

        if (PluginPermissions::allows($this->kirby(), 'prices.read') === false) {
            return [
                ...$props,
                'catalogue' => [
                    'error' => null,
                    'failedAt' => null,
                    'refreshedAt' => null,
                    'status' => 'empty',
                ],
                'currency' => null,
                'disabled' => true,
                'selected' => self::fallbackSelected($value),
                'sourceInactive' => false,
                'value' => $value,
            ];
        }

        try {
            $runtime = new RuntimeFactory($this->kirby());
            $settings = $runtime->settings();
            $currency = $settings->currency();

            if ($currency === null) {
                throw new InvalidArgumentException('Store currency is missing.');
            }

            $state = $runtime->stripePriceCatalogue()->current($currency);
            $selected = $this->selected($state['items'], $value, $this->kirby());

            return [
                ...$props,
                'catalogue' => self::status($state),
                'currency' => $currency,
                'selected' => $selected,
                'sourceInactive' => false,
                'value' => $value,
            ];
        } catch (\Throwable) {
            return [
                ...$props,
                'catalogue' => [
                    'error' => 'prices.configuration_invalid',
                    'failedAt' => null,
                    'refreshedAt' => null,
                    'status' => 'error',
                ],
                'currency' => null,
                'selected' => self::fallbackSelected($value),
                'sourceInactive' => false,
                'value' => $value,
            ];
        }
    }

    /** @return list<array<string, mixed>> */
    public function routes(): array
    {
        return self::catalogueRoutes();
    }

    /** @return list<array<string, mixed>> */
    public static function catalogueRoutes(string $prefix = ''): array
    {
        $pattern = $prefix === '' ? '/' : $prefix;

        return [
            [
                'pattern' => $pattern,
                'method' => 'GET',
                'action' => function (): array {
                    // Kirby binds field-route actions to its API instance.
                    // @phpstan-ignore-next-line variable.undefined
                    $api = $this;
                    /** @var Api $api */
                    return StripePriceField::apiResponse(
                        $api->kirby(),
                        $api->requestQuery('search'),
                        $api->requestQuery('page'),
                        false,
                    );
                },
            ],
            [
                'pattern' => $pattern,
                'method' => 'POST',
                'action' => function (): array {
                    // Kirby binds field-route actions to its API instance.
                    // @phpstan-ignore-next-line variable.undefined
                    $api = $this;
                    /** @var Api $api */
                    return StripePriceField::apiResponse(
                        $api->kirby(),
                        $api->requestQuery('search'),
                        $api->requestQuery('page'),
                        true,
                    );
                },
            ],
        ];
    }

    public function toFormValue(): string
    {
        $value = parent::toFormValue();

        return is_string($value) ? trim($value) : '';
    }

    public function toStoredValue(): string
    {
        $value = $this->toFormValue();

        if ($value === '') {
            return '';
        }

        try {
            return (new StripePriceReference($value))->priceId();
        } catch (InvalidProductException $error) {
            throw new InvalidArgumentException(message: $error->getMessage());
        }
    }

    public function type(): string
    {
        return 'stripe-checkout-price';
    }

    /** @return array<string, callable> */
    public function validations(): array
    {
        return [
            'stripePrice' => function (): bool {
                $this->toStoredValue();

                return true;
            },
        ];
    }

    /**
     * @return array{catalogue: array{error: ?string, failedAt: ?int, refreshedAt: ?int, status: string}, data: list<array<string, mixed>>, pagination: array{limit: int, page: int, pages: int, total: int}}
     */
    public static function apiResponse(
        App $kirby,
        mixed $query,
        mixed $page,
        bool $refresh,
    ): array {
        PluginPermissions::require($kirby, 'prices.read');
        $runtime = new RuntimeFactory($kirby);
        $currency = $runtime->settings()->currency();

        if ($currency === null) {
            throw new InvalidArgumentException('Store currency is missing.');
        }

        $result = $runtime->stripePriceCatalogue()->search(
            $currency,
            is_string($query) ? $query : null,
            is_numeric($page) ? (int) $page : 1,
            $refresh,
        );

        return [
            'catalogue' => self::status($result),
            'data' => array_map(
                static fn(ResolvedPrice $price): array => self::item($price, $kirby),
                $result['items'],
            ),
            'pagination' => [
                'limit' => 20,
                'page' => $result['page'],
                'pages' => $result['pages'],
                'total' => $result['total'],
            ],
        ];
    }

    /**
     * @param list<ResolvedPrice> $items
     * @return array<string, mixed>|null
     */
    private function selected(array $items, string $value, App $kirby): ?array
    {
        if ($value === '') {
            return null;
        }

        foreach ($items as $item) {
            if ($item->priceId() === $value) {
                return self::item($item, $kirby);
            }
        }

        return self::fallbackSelected($value);
    }

    /** @return array<string, mixed>|null */
    private static function fallbackSelected(string $value, bool $unavailable = true): ?array
    {
        if ($value === '') {
            return null;
        }

        return [
            'id' => $value,
            'icon' => $unavailable ? 'alert' : 'money',
            'info' => $value,
            'text' => self::translated('programmatordev.stripe-checkout.prices.savedReference'),
            ...($unavailable ? ['theme' => 'warning'] : []),
            'unavailable' => $unavailable,
        ];
    }

    /** @return array<string, mixed> */
    private static function item(ResolvedPrice $price, App $kirby): array
    {
        $amount = (new MoneyFormatter($kirby))->format(
            (new StripeCurrencyRegistry())->toMoney($price->unitPrice()),
        );
        $details = array_filter([
            $price->nickname(),
            $amount,
            strtoupper($price->unitPrice()->currency()),
            self::translated('programmatordev.stripe-checkout.prices.active'),
            self::translated(
                'programmatordev.stripe-checkout.prices.taxBehavior.' . $price->taxBehavior(),
            ),
        ]);

        return [
            'id' => $price->priceId(),
            'icon' => 'money',
            'info' => implode(' · ', $details),
            'text' => $price->name(),
        ];
    }

    private static function translated(string $key): string
    {
        $translation = I18n::translate($key);

        return is_string($translation) ? $translation : $key;
    }

    /**
     * @param array{items: list<ResolvedPrice>, refreshedAt: ?int, failedAt: ?int, error: ?string} $state
     * @return array{error: ?string, failedAt: ?int, refreshedAt: ?int, status: string}
     */
    private static function status(array $state): array
    {
        $status = match (true) {
            $state['error'] !== null && $state['items'] !== [] => 'stale',
            $state['error'] !== null => 'error',
            $state['refreshedAt'] !== null => 'ready',
            default => 'empty',
        };

        return [
            'error' => $state['error'],
            'failedAt' => $state['failedAt'],
            'refreshedAt' => $state['refreshedAt'],
            'status' => $status,
        ];
    }
}
