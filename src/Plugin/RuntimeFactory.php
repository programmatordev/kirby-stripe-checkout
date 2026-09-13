<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Plugin;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Uuid\Uuid;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutator;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartViewFactory;
use ProgrammatorDev\StripeCheckout\Cart\Internal\KirbySessionCartStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionCreator;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ProductRequestNormalizer;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestBuilder;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestContextFactory;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestCustomizer;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationReport;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Configuration\ProductConfiguration;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Internal\ClosureProductResolver;
use ProgrammatorDev\StripeCheckout\Product\Internal\GuardedProductResolver;
use ProgrammatorDev\StripeCheckout\Product\Internal\KirbyPageLocator;
use ProgrammatorDev\StripeCheckout\Product\Internal\KirbyPageProductResolver;
use ProgrammatorDev\StripeCheckout\Product\Internal\ProductOptionsFactory;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductOptions;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\StripeApiCheckoutSessionGateway;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceCatalogue;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceProviderInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceResolver;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripeApiPriceProvider;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripePrice;
use ProgrammatorDev\StripeCheckout\Stripe\StripeApiClientFactory;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\StripeApiTaxProvider;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeCatalogue;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxReadiness;
use ProgrammatorDev\StripeCheckout\Translation\LocaleResolver;
use Stripe\StripeClient;
use Stripe\Util\ApiVersion;

/**
 * Builds and owns the service graph for one public plugin operation.
 *
 * @internal
 */
final class RuntimeFactory
{
    private ?ConfigurationReport $configurationReport = null;

    private ?StripeClient $stripeClient = null;
    private ?TaxReadiness $taxReadiness = null;

    public function __construct(
        private readonly App $kirby,
    ) {}

    public function settings(): Settings
    {
        return $this->configurationReport()
            ->configurationOrFail()
            ->settings();
    }

    /** HTTP writes may defer presentation; public cart() reads remain eager. */
    public function cart(bool $resolve = true): ?Cart
    {
        /** @var array<string, mixed> $options */
        $options = $this->kirby->options();

        // Disabled carts must not open a browser session just to return null.
        if ((new ConfigurationResolver())->cartEnabled($options) === false) {
            return null;
        }

        $store = new KirbySessionCartStore($this->kirby->session(), Uuid::generate(...));
        $requestNormalizer = new ProductRequestNormalizer(function (ProductRequest $request): Product {
            // Rebuild context after login/language changes, even when a project
            // keeps the same Cart object for several operations in one request.
            $runtime = new self($this->kirby);
            $product = $runtime->resolveProduct($request);

            if ($product->price() instanceof StripePriceReference) {
                $currency = $runtime->settings()->currency();

                if ($currency === null) {
                    throw new ConfigurationException('configuration.required_missing', 'settings.currency');
                }

                // A syntactically valid ID is not proof the current Price is usable.
                $runtime->stripePriceResolver()->resolve($product->price(), $currency);
            }

            return $product;
        });
        $mutator = new CartMutator($store, $requestNormalizer, Uuid::generate(...));

        return (new CartViewFactory($this->kirby))->create($store->read(), $mutator, $resolve);
    }

    public function resolveProduct(ProductRequest $request): Product
    {
        return (new GuardedProductResolver($this->productResolver()))->resolve(
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

    public function productStripePriceFromField(Field $field): ?StripePrice
    {
        $value = $field->value();

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) === false) {
            throw new InvalidProductException('product.stripe_price_invalid');
        }

        return $this->productStripePrice($value);
    }

    public function productStripePrice(StripePriceReference|string $reference): StripePrice
    {
        $reference = is_string($reference)
            ? new StripePriceReference($reference)
            : $reference;
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
        $stripe = $this->configurationReport()->configurationOrFail()->stripe();

        // Partition by credentials and currency so key rotation cannot reuse
        // another account's or mode's last-good catalogue.
        return new PriceCatalogue(
            cache: $this->kirby->cache('programmatordev.stripe-checkout.prices'),
            provider: $provider,
            resolver: $provider === null ? null : new PriceResolver($provider),
            cacheKey: $stripe->hasSecretKey() ? $stripe->secretKeyFingerprint('prices') : 'unconfigured',
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

    public function taxCodeCatalogue(): TaxCodeCatalogue
    {
        $stripe = $this->configurationReport()->configurationOrFail()->stripe();

        return new TaxCodeCatalogue(
            cache: $this->kirby->cache('programmatordev.stripe-checkout.taxCodes'),
            provider: $stripe->hasSecretKey() ? new StripeApiTaxProvider($this->stripeClient()) : null,
            cacheKey: $stripe->hasSecretKey() ? $stripe->secretKeyFingerprint('tax-codes') : 'unconfigured',
        );
    }

    public function taxReadiness(): TaxReadiness
    {
        if ($this->taxReadiness !== null) {
            return $this->taxReadiness;
        }

        $stripe = $this->configurationReport()->configurationOrFail()->stripe();

        // Credential fingerprints isolate mode/account without another Stripe
        // request to identify the account. Key rotation safely starts cold.
        return $this->taxReadiness = new TaxReadiness(
            cache: $this->kirby->cache('programmatordev.stripe-checkout.taxSettings'),
            provider: $stripe->hasSecretKey() ? new StripeApiTaxProvider($this->stripeClient()) : null,
            cacheKey: $stripe->hasSecretKey() ? $stripe->secretKeyFingerprint('tax-settings') : 'unconfigured',
            liveMode: match ($stripe->secretKeyMode()) {
                CredentialMode::Test => false,
                CredentialMode::Live => true,
                CredentialMode::Unknown => null,
            },
        );
    }

    public function checkoutSessionGateway(): CheckoutSessionGatewayInterface
    {
        return new StripeApiCheckoutSessionGateway($this->stripeClient());
    }

    public function checkoutSessionRequest(SessionRequestContext $context): SessionRequest
    {
        $configuration = $this->configurationReport()->configurationOrFail();
        $request = (new SessionRequestBuilder(
            kirby: $this->kirby,
            settings: $configuration->settings(),
        ))->build($context);

        return (new SessionRequestCustomizer($this->kirby))->customize(
            $context,
            $request,
        );
    }

    public function checkoutSessionCreator(): CheckoutSessionCreator
    {
        $configuration = $this->configurationReport()->configurationOrFail();

        return new CheckoutSessionCreator(
            configuration: $configuration,
            requestContextFactory: new SessionRequestContextFactory($this->kirby),
            orderPageStore: new OrderPageStore($this->kirby),
            sessionGateway: $this->checkoutSessionGateway(),
            prepareSessionRequest: fn(SessionRequestContext $context): SessionRequest => $this->checkoutSessionRequest($context),
            stripeApiVersion: ApiVersion::CURRENT,
        );
    }

    public function configurationReport(): ConfigurationReport
    {
        /** @var array<string, mixed> $options */
        $options = $this->kirby->options();

        if ($this->configurationReport !== null) {
            return $this->configurationReport;
        }

        $resolver = new ConfigurationResolver(
            languageCode: $this->kirby->language()?->code(),
        );
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
        return new ProductOptionsFactory(
            $this->products(),
            $this->productContext(),
            stripePriceResolver: fn(StripePriceReference $reference): StripePrice => $this->productStripePrice($reference),
        );
    }

    private function configuredStripePriceProvider(): ?PriceProviderInterface
    {
        $stripe = $this->configurationReport()
            ->configurationOrFail()
            ->stripe();
        $secretKey = $stripe->secretKey();

        return $secretKey === null
            ? null
            : new StripeApiPriceProvider($this->stripeClient());
    }

    private function stripeClient(): StripeClient
    {
        if ($this->stripeClient !== null) {
            return $this->stripeClient;
        }

        $configuration = $this->configurationReport()
            ->configurationOrFail()
            ->stripe();
        $version = App::plugin(PluginMetadata::NAME)?->version();

        return $this->stripeClient = (new StripeApiClientFactory())->create(
            $configuration,
            $version,
        );
    }
}
