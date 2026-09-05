<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\Api;
use Kirby\Cms\App;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\FieldClass;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceCatalogue;
use ProgrammatorDev\StripeCheckout\Stripe\Price\ResolvedPrice;

/**
 * Exposes one scalar Stripe Price reference through Kirby's field API.
 *
 * @internal
 */
final class StripePriceField extends FieldClass
{
    private const PAGE_LIMIT = 20;

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
            $selected = $this->selected($state['items'], $value);

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
                    $view = $api->requestQuery('view');

                    if (in_array($view, ['products', 'prices'], true)) {
                        return StripePriceField::pickerResponse(
                            $api->kirby(),
                            $view,
                            $api->requestQuery('product'),
                            $api->requestQuery('search'),
                            $api->requestQuery('page'),
                        );
                    }

                    if ($view === 'selected') {
                        return StripePriceField::selectedResponse(
                            $api->kirby(),
                            $api->requestQuery('prices') ?? $api->requestQuery('price'),
                        );
                    }

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
        [$catalogue, $currency] = self::catalogue($kirby);
        $result = $catalogue->search(
            $currency,
            is_string($query) ? $query : null,
            is_numeric($page) ? (int) $page : 1,
            $refresh,
        );

        return [
            'catalogue' => self::status($result),
            'data' => array_map(
                static fn(ResolvedPrice $price): array => self::item($price),
                $result['items'],
            ),
            'pagination' => [
                'limit' => self::PAGE_LIMIT,
                'page' => $result['page'],
                'pages' => $result['pages'],
                'total' => $result['total'],
            ],
        ];
    }

    /**
     * Presents the cached flat catalogue as a Product-first picker.
     *
     * @return array{catalogue: array{error: ?string, failedAt: ?int, refreshedAt: ?int, status: string}, data: list<array<string, mixed>>, pagination: array{limit: int, page: int, pages: int, total: int}}
     */
    public static function pickerResponse(
        App $kirby,
        string $view,
        mixed $productId,
        mixed $query,
        mixed $page,
    ): array {
        [$catalogue, $currency] = self::catalogue($kirby);
        $state = $catalogue->load($currency);
        $query = is_string($query) ? mb_strtolower(trim($query)) : '';
        $page = is_numeric($page) ? (int) $page : 1;

        if ($view === 'prices') {
            $productId = is_string($productId) ? $productId : '';
            $prices = array_values(array_filter(
                $state['items'],
                static fn(ResolvedPrice $price): bool => $price->productId() === $productId
                    && self::priceMatches($price, $query),
            ));
            $data = array_map(
                static fn(ResolvedPrice $price): array => self::pickerPriceItem($price),
                $prices,
            );
        } else {
            $groups = [];

            foreach ($state['items'] as $price) {
                $groups[$price->productId()] ??= [
                    'id' => $price->productId(),
                    'name' => $price->name(),
                    'images' => $price->images(),
                    'prices' => [],
                ];
                $groups[$price->productId()]['prices'][] = $price;
            }

            $groups = array_values(array_filter(
                $groups,
                static fn(array $group): bool => self::productMatches($group, $query),
            ));
            $data = array_map(
                static fn(array $group): array => self::pickerProductItem($group),
                $groups,
            );
        }

        return [
            'catalogue' => self::status($state),
            ...self::paginate($data, $page),
        ];
    }

    /**
     * Resolves one or more saved IDs from the cache without contacting Stripe.
     *
     * @return array{catalogue: array{error: ?string, failedAt: ?int, refreshedAt: ?int, status: string}, data: list<array<string, mixed>>, pagination: array{limit: int, page: int, pages: int, total: int}}
     */
    public static function selectedResponse(App $kirby, mixed $priceIds): array
    {
        [$catalogue, $currency] = self::catalogue($kirby);
        $state = $catalogue->current($currency);
        $priceIds = is_array($priceIds)
            ? $priceIds
            : (is_string($priceIds) ? explode(',', $priceIds) : []);
        $priceIds = array_fill_keys(array_values(array_filter(
            $priceIds,
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        )), true);
        $data = [];

        foreach ($state['items'] as $price) {
            if (isset($priceIds[$price->priceId()])) {
                $data[] = self::item($price);
            }
        }

        return [
            'catalogue' => self::status($state),
            ...self::paginate($data, 1),
        ];
    }

    /**
     * @param list<ResolvedPrice> $items
     * @return array<string, mixed>|null
     */
    private function selected(array $items, string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        foreach ($items as $item) {
            if ($item->priceId() === $value) {
                return self::item($item);
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
    private static function item(ResolvedPrice $price): array
    {
        $text = array_filter([
            $price->name(),
            $price->nickname(),
        ]);

        return [
            'id' => $price->priceId(),
            'icon' => 'money',
            'image' => self::itemImage($price->images()[0] ?? null, 'money'),
            'info' => self::formattedAmount($price),
            'text' => implode(' · ', $text),
        ];
    }

    /** @return array{PriceCatalogue, string} */
    private static function catalogue(App $kirby): array
    {
        PluginPermissions::require($kirby, 'prices.read');
        $runtime = new RuntimeFactory($kirby);
        $currency = $runtime->settings()->currency();

        if ($currency === null) {
            throw new InvalidArgumentException('Store currency is missing.');
        }

        return [$runtime->stripePriceCatalogue(), $currency];
    }

    /**
     * @param array{id: string, name: string, images: list<string>, prices: list<ResolvedPrice>} $group
     * @return array<string, mixed>
     */
    private static function pickerProductItem(array $group): array
    {
        $count = count($group['prices']);
        return [
            'id' => $group['id'],
            'image' => self::itemImage($group['images'][0] ?? null, 'box'),
            'info' => I18n::template(
                'programmatordev.stripe-checkout.prices.productCount.' . ($count === 1 ? 'one' : 'many'),
                ['count' => $count],
            ),
            'text' => $group['name'],
        ];
    }

    /** @return array<string, mixed> */
    private static function pickerPriceItem(ResolvedPrice $price): array
    {
        $amount = self::formattedAmount($price);
        $nickname = $price->nickname();

        return [
            'id' => $price->priceId(),
            'image' => self::itemImage($price->images()[0] ?? null, 'money'),
            'info' => $amount,
            'selected' => self::item($price),
            'text' => $nickname ?? $price->name(),
        ];
    }

    private static function formattedAmount(ResolvedPrice $price): string
    {
        $money = $price->price();

        return $money->getAmount()->toString() . ' ' . $money->getCurrency()->getCurrencyCode();
    }

    /** @return array<string, string> */
    private static function itemImage(?string $src, string $icon): array
    {
        return $src === null
            ? ['back' => 'pattern', 'color' => 'gray-500', 'icon' => $icon]
            : ['src' => $src];
    }

    /**
     * @param array{id: string, name: string, images: list<string>, prices: list<ResolvedPrice>} $group
     */
    private static function productMatches(array $group, string $query): bool
    {
        if ($query === '') {
            return true;
        }

        $values = [$group['id'], $group['name']];

        foreach ($group['prices'] as $price) {
            $values[] = $price->priceId();
            $values[] = $price->nickname() ?? '';
        }

        return str_contains(mb_strtolower(implode(' ', $values)), $query);
    }

    private static function priceMatches(ResolvedPrice $price, string $query): bool
    {
        if ($query === '') {
            return true;
        }

        return str_contains(mb_strtolower(implode(' ', [
            $price->priceId(),
            $price->nickname() ?? '',
        ])), $query);
    }

    /**
     * @param list<array<string, mixed>> $data
     * @return array{data: list<array<string, mixed>>, pagination: array{limit: int, page: int, pages: int, total: int}}
     */
    private static function paginate(array $data, int $page): array
    {
        $total = count($data);
        $pages = max(1, (int) ceil($total / self::PAGE_LIMIT));
        $page = min(max(1, $page), $pages);

        return [
            'data' => array_slice($data, ($page - 1) * self::PAGE_LIMIT, self::PAGE_LIMIT),
            'pagination' => [
                'limit' => self::PAGE_LIMIT,
                'page' => $page,
                'pages' => $pages,
                'total' => $total,
            ],
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
