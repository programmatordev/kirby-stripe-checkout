<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use InvalidArgumentException;
use Kirby\Content\Field as ContentField;
use Kirby\Exception\InvalidArgumentException as KirbyInvalidArgumentException;
use Kirby\Form\FieldClass;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Internal\ProductOptionsDefinition;
use ProgrammatorDev\StripeCheckout\Product\Internal\ProductOptionsSchema;

/**
 * Adapts canonical product-option storage to Kirby's custom Panel field API.
 *
 * @internal
 */
final class OptionsField extends FieldClass
{
    private readonly string $currency;
    private readonly string $priceSource;

    /** @var list<array<string, mixed>> */
    private readonly array $presets;

    /** @param array<string, mixed> $params */
    public function __construct(array $params = [])
    {
        parent::__construct($params);

        $this->priceSource = $this->resolvePriceSource($params['priceSource'] ?? null);
        $this->currency = $this->resolveCurrency($params['currency'] ?? null);
        $presets = [];

        foreach (is_array($params['presets'] ?? null) ? $params['presets'] : [] as $preset) {
            if (is_array($preset)) {
                /** @var array<string, mixed> $preset */
                $presets[] = $preset;
            }
        }

        $this->presets = $presets;
    }

    /** @return array{options: array<mixed>, variants: array<mixed>} */
    public function emptyValue(): array
    {
        return [
            'options' => [],
            'variants' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        /** @var array<string, mixed> $props */
        $props = parent::props();
        $automaticTax = false;

        try {
            $runtime = new RuntimeFactory($this->kirby());
            $settings = $runtime->settings();
            $automaticTax = $settings->automaticTax();

            if ($automaticTax && $settings->priceSource() === PriceSource::Kirby && PluginPermissions::allows($this->kirby(), 'taxCodes.read')) {
                $runtime->taxCodeCatalogue()->load();
            }
        } catch (ConfigurationException) {
            // Keep the editor usable while store configuration is incomplete.
        }

        return [
            ...$props,
            'currency' => $this->currency,
            'automaticTax' => $automaticTax,
            'presets' => $this->presets,
            'priceSource' => $this->priceSource,
            'pricesReadable' => PluginPermissions::allows($this->kirby(), 'prices.read'),
            'taxCodesReadable' => PluginPermissions::allows($this->kirby(), 'taxCodes.read'),
            'serverTechnicalLocked' => $this->technicalLocked(),
            'value' => $this->toFormValue(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function routes(): array
    {
        return [
            ...StripePriceField::catalogueRoutes('prices'),
            ...TaxCodeField::catalogueRoutes('tax-codes'),
        ];
    }

    /** @return array<string, mixed> */
    public function toFormValue(): array
    {
        try {
            $schema = new ProductOptionsSchema();
            $value = parent::toFormValue();

            if ($this->technicalLocked() === false) {
                return $schema->canonical($value)->toArray();
            }

            return $schema->localized($this->canonicalValue(), $value)->toArray();
        } catch (InvalidArgumentException $error) {
            // Keep the product schema framework-neutral and translate its validation failure only at Kirby's Field boundary.
            throw new KirbyInvalidArgumentException(message: $error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function toStoredValue(): array
    {
        try {
            $schema = new ProductOptionsSchema();
            $value = parent::toStoredValue();

            if ($this->technicalLocked() === false) {
                return $schema->canonical($value)->toArray();
            }

            return $schema->overlay($this->canonicalValue(), $value);
        } catch (InvalidArgumentException $error) {
            throw new KirbyInvalidArgumentException(message: $error->getMessage());
        }
    }

    public function type(): string
    {
        return 'stripe-checkout-options';
    }

    /** @return array<string, callable> */
    public function validations(): array
    {
        return [
            'variantData' => function (): bool {
                $this->toStoredValue();

                return true;
            },
        ];
    }

    private function canonicalValue(): ProductOptionsDefinition
    {
        $defaultLanguage = $this->kirby()->defaultLanguage();

        if ($defaultLanguage === null) {
            return (new ProductOptionsSchema())->canonical(parent::toFormValue());
        }

        // Commerce data has one authority; translations only overlay labels.
        $contentField = $this->model()
            ->content($defaultLanguage->code())
            ->get($this->name());

        return (new ProductOptionsSchema())->canonical(
            $contentField instanceof ContentField ? $contentField->value() : null,
        );
    }

    private function resolveCurrency(mixed $currency): string
    {
        if (is_string($currency) && $currency !== '') {
            return strtoupper($currency);
        }

        try {
            return (new RuntimeFactory($this->kirby()))->settings()->currency() ?? 'EUR';
        } catch (ConfigurationException) {
            return 'EUR';
        }
    }

    private function resolvePriceSource(mixed $priceSource): string
    {
        if (is_string($priceSource) && $priceSource !== '') {
            return PriceSource::from($priceSource)->value;
        }

        try {
            return (new RuntimeFactory($this->kirby()))->settings()->priceSource()->value;
        } catch (ConfigurationException) {
            return PriceSource::Kirby->value;
        }
    }

    private function technicalLocked(): bool
    {
        // Secondary languages may translate labels but cannot change identity, combinations, availability or other commerce data.
        return $this->siblings->language()->isDefault() === false;
    }
}
