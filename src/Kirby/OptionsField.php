<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use InvalidArgumentException as DataException;
use Kirby\Content\Field as ContentField;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\FieldClass;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Internal\VariantSchema;

/**
 * Adapts canonical variant storage to Kirby's custom Panel field API.
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

        return [
            ...$props,
            'currency' => $this->currency,
            'presets' => $this->presets,
            'priceSource' => $this->priceSource,
            'pricesReadable' => PluginPermissions::allows($this->kirby(), 'prices.read'),
            'serverTechnicalLocked' => $this->technicalLocked(),
            'value' => $this->toFormValue(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function routes(): array
    {
        return StripePriceField::catalogueRoutes('prices');
    }

    /** @return array<string, mixed> */
    public function toFormValue(): array
    {
        try {
            $schema = new VariantSchema();
            $value = parent::toFormValue();

            if ($this->technicalLocked() === false) {
                return $schema->canonical($value);
            }

            return $schema->localized($this->canonicalValue(), $value);
        } catch (DataException $error) {
            throw new InvalidArgumentException(message: $error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function toStoredValue(): array
    {
        try {
            $schema = new VariantSchema();
            $value = parent::toStoredValue();

            if ($this->technicalLocked() === false) {
                return $schema->canonical($value);
            }

            return $schema->overlay($this->canonicalValue(), $value);
        } catch (DataException $error) {
            throw new InvalidArgumentException(message: $error->getMessage());
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

    /**
     * @return array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>}
     */
    private function canonicalValue(): array
    {
        $defaultLanguage = $this->kirby()->defaultLanguage();

        if ($defaultLanguage === null) {
            return (new VariantSchema())->canonical(parent::toFormValue());
        }

        // Commerce data has one authority; translations only overlay labels.
        $contentField = $this->model()
            ->content($defaultLanguage->code())
            ->get($this->name());

        return (new VariantSchema())->canonical(
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
        // Secondary languages may translate labels but cannot change identity,
        // combinations, availability or other commerce data.
        return $this->siblings->language()->isDefault() === false;
    }
}
