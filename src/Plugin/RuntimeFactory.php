<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Plugin;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationReport;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\ProductConfiguration;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Internal\ClosureProductResolver;
use ProgrammatorDev\StripeCheckout\Product\Internal\KirbyPageLocator;
use ProgrammatorDev\StripeCheckout\Product\Internal\KirbyPageProductResolver;
use ProgrammatorDev\StripeCheckout\Product\Internal\ProductOptionsFactory;
use ProgrammatorDev\StripeCheckout\Product\Internal\ProductResolutionService;
use ProgrammatorDev\StripeCheckout\Product\ProductOptions;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceCatalogue;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceProviderInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceResolver;
use ProgrammatorDev\StripeCheckout\Stripe\Price\ResolvedPrice;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripeApiPriceProvider;
use ProgrammatorDev\StripeCheckout\Translation\LocaleResolver;
use Stripe\StripeClient;

/**
 * Builds and owns the service graph for one public plugin operation.
 *
 * @internal
 */
final class RuntimeFactory
{
    private ?ConfigurationReport $configurationReport = null;

    public function __construct(
        private readonly App $kirby,
    ) {}

    public function settings(): Settings
    {
        return $this->configurationReport()
            ->configurationOrFail()
            ->settings();
    }

    public function resolveProduct(ProductRequest $request): ResolvedProduct
    {
        return (new ProductResolutionService($this->productResolver()))->resolve(
            $request,
            $this->productContext(),
        );
    }

    public function productOptions(Page|string $reference): ProductOptions
    {
        $page = (new KirbyPageLocator())->find($this->kirby->site(), $reference);

        return $this->productOptionsFactory()->forPage(
            $page,
            $this->products()->fields()['options'],
        );
    }

    public function productOptionsFromField(Field $field): ProductOptions
    {
        return $this->productOptionsFactory()->forField($field);
    }

    public function productStripePriceFromField(Field $field): ?ResolvedPrice
    {
        $value = $field->value();

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) === false) {
            throw new InvalidProductException('product.stripe_price_invalid');
        }

        $reference = new StripePriceReference($value);
        $currency = $this->settings()->currency();

        if ($currency === null) {
            throw new ConfigurationException(
                'configuration.required_missing',
                'settings.currency',
            );
        }

        return $this->stripePriceCatalogue()->find($reference->priceId(), $currency)
            ?? throw new InvalidProductException('product.stripe_price_unavailable');
    }

    public function stripePriceCatalogue(): PriceCatalogue
    {
        $provider = $this->configuredStripePriceProvider();

        return new PriceCatalogue(
            $this->kirby->cache('programmatordev.stripe-checkout.prices'),
            $provider,
            $provider === null ? null : new PriceResolver($provider),
        );
    }

    public function stripePriceResolver(): PriceResolver
    {
        $provider = $this->configuredStripePriceProvider();

        if ($provider === null) {
            throw new ConfigurationException(
                'configuration.credential_missing',
                'stripe.secretKey',
            );
        }

        return new PriceResolver($provider);
    }

    public function configurationReport(): ConfigurationReport
    {
        /** @var array<string, mixed> $options */
        $options = $this->kirby->options();

        if ($this->configurationReport !== null) {
            return $this->configurationReport;
        }

        $resolver = new ConfigurationResolver();
        $phpReport = $resolver->resolve($options);

        if ($phpReport->isValid() === false) {
            return $this->configurationReport = $phpReport;
        }

        try {
            $pageSettings = (new StripeCheckoutPageStore($this->kirby))->settings();
        } catch (ConfigurationException $error) {
            return $this->configurationReport = ConfigurationReport::invalid($error);
        }

        return $this->configurationReport = $resolver->resolve(
            $options,
            $pageSettings,
        );
    }

    private function products(): ProductConfiguration
    {
        return $this->configurationReport()
            ->configurationOrFail()
            ->products();
    }

    private function productResolver(): ProductResolverInterface
    {
        $configured = $this->products()->resolver();

        return match (true) {
            $configured instanceof ProductResolverInterface => $configured,
            $configured instanceof Closure => new ClosureProductResolver($configured),
            default => new KirbyPageProductResolver($this->products()),
        };
    }

    private function productContext(): ProductResolutionContext
    {
        $settings = $this->settings();

        return new ProductResolutionContext(
            site: $this->kirby->site(),
            user: $this->kirby->user(),
            languageCode: $this->kirby->language()?->code(),
            locale: (new LocaleResolver($this->kirby))->resolve(),
            priceSource: $settings->priceSource(),
            settings: $settings,
        );
    }

    private function productOptionsFactory(): ProductOptionsFactory
    {
        return new ProductOptionsFactory($this->products(), $this->productContext());
    }

    private function configuredStripePriceProvider(): ?PriceProviderInterface
    {
        $stripe = $this->configurationReport()
            ->configurationOrFail()
            ->stripe();
        $secretKey = $stripe->secretKey();

        return $secretKey === null
            ? null
            : new StripeApiPriceProvider(new StripeClient($secretKey));
    }
}
