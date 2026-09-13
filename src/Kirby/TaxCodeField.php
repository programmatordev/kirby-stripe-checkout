<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\Api;
use Kirby\Cms\App;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\FieldClass;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Stripe\CataloguePagination;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeCatalogueErrorCode;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;

/** Adapts optional scalar classification to Kirby's picker API. @internal */
final class TaxCodeField extends FieldClass
{
    public function emptyValue(): string
    {
        return '';
    }

    public function isDisabled(): bool
    {
        return parent::isDisabled()
            || PluginPermissions::allows($this->kirby(), 'taxCodes.read') === false
            || $this->sourceInactive();
    }

    public function type(): string
    {
        return 'stripe-checkout-tax-code';
    }

    public function toFormValue(): string
    {
        $value = parent::toFormValue();

        return is_string($value) ? trim($value) : '';
    }

    public function toStoredValue(): string
    {
        $value = $this->toFormValue();

        // Kirby still converts disabled fields when saving the form. Dormant
        // classification must survive unrelated edits without active validation.
        if ($value === '' || $this->sourceInactive()) {
            return $value;
        }

        try {
            return (new TaxCode($value))->id();
        } catch (InvalidProductException $error) {
            throw new InvalidArgumentException(message: $error->getMessage());
        }
    }

    /** @return array<string, callable> */
    public function validations(): array
    {
        return [
            'taxCode' => function (): bool {
                $this->toStoredValue();

                return true;
            },
        ];
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        /** @var array<string, mixed> $props */
        $props = parent::props();
        $value = $this->toFormValue();
        $inactive = $this->sourceInactive();
        $state = [
            'items' => [],
            'refreshedAt' => null,
            'failedAt' => null,
            'error' => null,
        ];

        try {
            $runtime = new RuntimeFactory($this->kirby());

            if (PluginPermissions::allows($this->kirby(), 'taxCodes.read')) {
                // Only relevant, authorized Panel loads own automatic refresh.
                $state = $inactive ? $runtime->taxCodeCatalogue()->cached() : $runtime->taxCodeCatalogue()->load();
            }
        } catch (\Throwable) {
            $state['error'] = TaxCodeCatalogueErrorCode::REFRESH_FAILED;
        }

        $selected = $value === '' ? null : [
            'id' => $value,
            'icon' => $inactive ? 'tag' : 'alert',
            'info' => $value,
            'text' => I18n::translate('programmatordev.stripe-checkout.taxCodes.savedReference'),
            'unavailable' => $inactive === false,
            ...($inactive ? [] : ['theme' => 'warning']),
        ];

        foreach ($state['items'] as $code) {
            if ($code->id() === $value) {
                $selected = self::item($code);
                break;
            }
        }

        return [
            ...$props,
            'catalogue' => self::status($state),
            'catalogueReadable' => PluginPermissions::allows($this->kirby(), 'taxCodes.read'),
            'disabled' => $this->isDisabled() || $inactive,
            'sourceInactive' => $inactive,
            'selected' => $selected,
            'value' => $value,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function routes(): array
    {
        return self::catalogueRoutes();
    }

    private function sourceInactive(): bool
    {
        try {
            $settings = (new RuntimeFactory($this->kirby()))->settings();

            return $settings->automaticTax() === false || $settings->priceSource() !== PriceSource::Kirby;
        } catch (ConfigurationException) {
            return true;
        }
    }

    /** @return list<array<string, mixed>> */
    public static function catalogueRoutes(string $prefix = ''): array
    {
        $methods = ['GET', 'POST'];
        $routes = [];

        foreach ($methods as $method) {
            $routes[] = [
                'pattern' => $prefix === '' ? '/' : $prefix,
                'method' => $method,
                'action' => function () use ($method): array {
                    // Kirby binds field-route actions to the API, not this field.
                    // @phpstan-ignore-next-line variable.undefined
                    $api = $this;
                    /** @var Api $api */
                    return TaxCodeField::apiResponse(
                        kirby: $api->kirby(),
                        query: $api->requestQuery('search'),
                        page: $api->requestQuery('page'),
                        refresh: $method === 'POST',
                        selected: $api->requestQuery('view') === 'selected'
                            ? $api->requestQuery('taxCodes') ?? $api->requestQuery('taxCode') ?? []
                            : null,
                    );
                },
            ];
        }

        return $routes;
    }

    /** @return array{catalogue: array{error: ?string, failedAt: ?int, refreshedAt: ?int, status: string}, data: list<array<string, mixed>>, pagination: array{limit: int, page: int, pages: int, total: int}} */
    public static function apiResponse(App $kirby, mixed $query, mixed $page, bool $refresh, mixed $selected = null): array
    {
        PluginPermissions::require($kirby, 'taxCodes.read');
        $catalogue = (new RuntimeFactory($kirby))->taxCodeCatalogue();

        if ($selected !== null && $refresh === false) {
            // Saved-reference hydration never contacts Stripe.
            $state = $catalogue->cached();
            $ids = is_array($selected) ? $selected : (is_string($selected) ? explode(',', $selected) : []);
            $items = array_values(array_filter($state['items'], static fn(TaxCode $code): bool => in_array($code->id(), $ids, true)));
            $result = CataloguePagination::paginate($items, 1);
        } else {
            $result = $catalogue->search(is_string($query) ? $query : null, is_numeric($page) ? (int) $page : 1, $refresh);
            $state = $result;
        }

        return [
            'catalogue' => self::status($state),
            'data' => array_map(static fn(TaxCode $code): array => self::item($code), $result['items']),
            'pagination' => [
                'limit' => CataloguePagination::LIMIT,
                'page' => $result['page'],
                'pages' => $result['pages'],
                'total' => $result['total'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function item(TaxCode $code): array
    {
        return [
            'id' => $code->id(),
            'icon' => 'tag',
            'text' => $code->providerName(),
            'info' => $code->providerDescription(),
        ];
    }

    /**
     * @param array{items: list<TaxCode>, refreshedAt: ?int, failedAt: ?int, error: ?string} $state
     * @return array{error: ?string, failedAt: ?int, refreshedAt: ?int, status: string}
     */
    private static function status(array $state): array
    {
        return [
            'error' => $state['error'],
            'failedAt' => $state['failedAt'],
            'refreshedAt' => $state['refreshedAt'],
            'status' => match (true) {
                $state['error'] !== null && $state['items'] !== [] => 'stale',
                $state['error'] !== null => 'error',
                $state['refreshedAt'] !== null => 'ready',
                default => 'empty',
            },
        ];
    }
}
