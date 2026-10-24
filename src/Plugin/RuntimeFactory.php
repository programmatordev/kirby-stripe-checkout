<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Plugin;

use Closure;
use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Uuid\Uuid;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutator;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartViewFactory;
use ProgrammatorDev\StripeCheckout\Cart\Internal\KirbySessionCartStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptContextResolver;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutBootstrapFactory;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutFormResultStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutPreparationFactory;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutResolver;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutResultResolver;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutResultStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionCreator;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionReconciler;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionRetriever;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSubmissionFactory;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutUrlBuilder;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CompletedCheckoutCartClearer;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\DisputeRetriever;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\IncompleteOrderRecovery;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ProductRequestNormalizer;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\RefundRetriever;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestBuilder;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestContextFactory;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestCustomizer;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationErrorCode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationReport;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\ProductConfiguration;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;
use ProgrammatorDev\StripeCheckout\Configuration\ShippingConfiguration;
use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Diagnostics\Internal\DiagnosticReportStore;
use ProgrammatorDev\StripeCheckout\Diagnostics\RemoteDiagnostics;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingRunner;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingStateStore;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\OrderReferenceSelector;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\WebhookRoutes;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderNumberFormatter;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Internal\ClosureProductResolver;
use ProgrammatorDev\StripeCheckout\Product\Internal\GuardedProductResolver;
use ProgrammatorDev\StripeCheckout\Product\Internal\KirbyPageLocator;
use ProgrammatorDev\StripeCheckout\Product\Internal\KirbyPageProductResolver;
use ProgrammatorDev\StripeCheckout\Product\Internal\ProductOptionsFactory;
use ProgrammatorDev\StripeCheckout\Product\Internal\TaxCodeValidator;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use ProgrammatorDev\StripeCheckout\Product\ProductOptions;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Shipping\Internal\ClosureShippingResolver;
use ProgrammatorDev\StripeCheckout\Shipping\Internal\ShippingQuotePipeline;
use ProgrammatorDev\StripeCheckout\Shipping\Internal\ShippingZoneResolver;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingResolverInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\StripeApiCheckoutSessionGateway;
use ProgrammatorDev\StripeCheckout\Stripe\Diagnostics\StripeApiDiagnosticsGateway;
use ProgrammatorDev\StripeCheckout\Stripe\Dispute\StripeApiDisputeGateway;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceCatalogue;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceProviderInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceResolver;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripeApiPriceProvider;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripePrice;
use ProgrammatorDev\StripeCheckout\Stripe\Refund\StripeApiRefundGateway;
use ProgrammatorDev\StripeCheckout\Stripe\StripeApiClientFactory;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\StripeApiTaxProvider;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeCatalogue;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
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

    public function __construct(
        private readonly App $kirby,
    ) {}

    public function settings(): Settings
    {
        return $this->configurationReport()
            ->configurationOrFail()
            ->settings();
    }

    public function checkoutBootstrapFactory(): CheckoutBootstrapFactory
    {
        $configuration = $this->configurationReport()->configurationOrFail();
        $session = $this->kirby->session();
        $cartStore = new KirbySessionCartStore($session, Uuid::generate(...));
        $attemptStore = new BrowserAttemptStore($session);

        return new CheckoutBootstrapFactory(
            kirby: $this->kirby,
            configuration: $configuration,
            requestContextFactory: new SessionRequestContextFactory($this->kirby),
            checkoutUrlBuilder: new CheckoutUrlBuilder($this->kirby),
            cartClearer: $this->createCompletedCheckoutCartClearer(
                $cartStore,
                $this->createCartMutator($cartStore),
                $attemptStore,
            ),
            attemptStore: $attemptStore,
            formResultStore: new CheckoutFormResultStore($session),
        );
    }

    public function checkoutFormResultStore(): CheckoutFormResultStore
    {
        return new CheckoutFormResultStore($this->kirby->session());
    }

    public function browserAttemptStore(): BrowserAttemptStore
    {
        return new BrowserAttemptStore($this->kirby->session());
    }

    public function checkoutResultStore(): CheckoutResultStore
    {
        return new CheckoutResultStore($this->kirby->session());
    }

    public function checkoutResultResolver(): CheckoutResultResolver
    {
        return new CheckoutResultResolver(
            kirby: $this->kirby,
            results: $this->checkoutResultStore(),
            orders: $this->orderPageStore(),
        );
    }

    public function orderPageStore(): OrderPageStore
    {
        return new OrderPageStore($this->kirby);
    }

    public function checkoutSubmissionFactory(): CheckoutSubmissionFactory
    {
        $configuration = $this->configurationReport()->configurationOrFail();
        $session = $this->kirby->session();

        return new CheckoutSubmissionFactory(
            kirby: $this->kirby,
            configuration: $configuration,
            resolver: $this->checkoutResolver(),
            cartStore: new KirbySessionCartStore($session, Uuid::generate(...)),
            attemptStore: new BrowserAttemptStore($session),
            orderPageStore: $this->orderPageStore(),
        );
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
        $mutator = $this->createCartMutator($store);
        $snapshot = $this->createCompletedCheckoutCartClearer(
            $store,
            $mutator,
            new BrowserAttemptStore($this->kirby->session()),
        )->clearIfCheckoutCompleted(new DateTimeImmutable());

        return $this->cartViewFactory()->create($snapshot, $mutator, $resolve);
    }

    /** Browser-return cleanup uses the same guarded mutation without depending on current cart enablement. */
    public function cartMutator(): CartMutator
    {
        return $this->createCartMutator(new KirbySessionCartStore($this->kirby->session(), Uuid::generate(...)));
    }

    private function createCartMutator(KirbySessionCartStore $cartStore): CartMutator
    {
        $requestNormalizer = new ProductRequestNormalizer(
            fn(ProductRequest $request): Product => (new self($this->kirby))->checkoutResolver()->cartProduct($request),
        );

        return new CartMutator($cartStore, $requestNormalizer, Uuid::generate(...));
    }

    private function createCompletedCheckoutCartClearer(
        KirbySessionCartStore $cartStore,
        CartMutator $cartMutator,
        BrowserAttemptStore $attemptStore,
    ): CompletedCheckoutCartClearer {
        return new CompletedCheckoutCartClearer(
            cartStore: $cartStore,
            cartMutator: $cartMutator,
            attemptStore: $attemptStore,
            attemptContextResolver: new BrowserAttemptContextResolver($this->kirby, $attemptStore),
            orderPageStore: $this->orderPageStore(),
        );
    }

    private function taxCodeValidator(): TaxCodeValidator
    {
        return new TaxCodeValidator($this->taxCodeCatalogue());
    }

    public function productOptions(Page|string $reference): ProductOptions
    {
        $page = (new KirbyPageLocator())->find($this->kirby->site(), $reference);

        return $this->productOptionsFactory()->forPage(
            $page,
            $this->products()->fields()->options(),
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
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INVALID);
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
                ConfigurationErrorCode::REQUIRED_MISSING,
                'settings.currency',
            );
        }

        return $this->stripePriceCatalogue()->find($reference->priceId(), $currency)
            ?? throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_UNAVAILABLE);
    }

    public function stripePriceCatalogue(): PriceCatalogue
    {
        $provider = $this->configuredStripePriceProvider();
        $stripe = $this->configurationReport()->configurationOrFail()->stripe();

        // Partition by credentials and currency so key rotation cannot reuse another account's or mode's last-good catalogue.
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
                ConfigurationErrorCode::CREDENTIAL_MISSING,
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

    public function checkoutSessionGateway(): CheckoutSessionGatewayInterface
    {
        return new StripeApiCheckoutSessionGateway($this->stripeClient());
    }

    public function cartViewFactory(): CartViewFactory
    {
        return new CartViewFactory(
            kirby: $this->kirby,
            // A retained Cart can survive login/logout and language changes in the same request.
            // Capture current inputs for each new presentation.
            checkoutResolverFactory: fn(): CheckoutResolver => (new self($this->kirby))->checkoutResolver(),
        );
    }

    public function checkoutResolver(): CheckoutResolver
    {
        return new CheckoutResolver(
            settings: $this->settings(),
            productContextFactory: $this->productContext(...),
            productResolver: new GuardedProductResolver($this->productResolver()),
            stripePriceResolverFactory: $this->stripePriceResolver(...),
            taxCodeValidator: $this->taxCodeValidator(),
            shippingQuotes: new ShippingQuotePipeline($this->kirby, $this->shippingResolver()),
            customShippingResolver: $this->shipping()->resolver() !== null,
        );
    }

    public function checkoutPreparationFactory(): CheckoutPreparationFactory
    {
        /** @var array<string, mixed> $options */
        $options = $this->kirby->options();

        return new CheckoutPreparationFactory(
            resolver: $this->checkoutResolver(),
            orderNumbers: new OrderNumberFormatter((new ConfigurationResolver())->orderNumberFormatter($options)),
            requestBuilder: new SessionRequestBuilder(
                kirby: $this->kirby,
                settings: $this->settings(),
                checkoutUrlBuilder: new CheckoutUrlBuilder($this->kirby),
            ),
            requestCustomizer: new SessionRequestCustomizer($this->kirby),
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
            preparationFactory: $this->checkoutPreparationFactory(),
            stripeApiVersion: ApiVersion::CURRENT,
        );
    }

    /** Historical order reads need server credentials, not a valid current storefront or presentation key. */
    public function checkoutSessionReconciler(?StripeConfiguration $stripe = null): CheckoutSessionReconciler
    {
        // The signed HTTP edge has already resolved these credentials for verification.
        // Reuse that immutable value within the operation instead of validating configuration twice.
        if ($stripe === null) {
            /** @var array<string, mixed> $options */
            $options = $this->kirby->options();
            $stripe = (new ConfigurationResolver())->stripe($options);
        }

        $client = (new StripeApiClientFactory())->create($stripe, App::plugin(PluginMetadata::NAME)?->version());

        return new CheckoutSessionReconciler(
            orders: new OrderPageStore($this->kirby),
            retriever: new CheckoutSessionRetriever(new StripeApiCheckoutSessionGateway($client)),
            credentialMode: $stripe->secretKeyMode(),
            refundRetriever: new RefundRetriever(
                new StripeApiRefundGateway($client),
            ),
            disputeRetriever: new DisputeRetriever(new StripeApiDisputeGateway($client)),
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

    public function incompleteOrderRecovery(): IncompleteOrderRecovery
    {
        $configuration = $this->configurationReport()->configurationOrFail();
        $stripe = $configuration->stripe();
        $orders = new OrderPageStore($this->kirby);
        $gateway = $this->checkoutSessionGateway();

        return new IncompleteOrderRecovery(
            orders: $orders,
            gateway: $gateway,
            // Recovery refreshes Checkout/payment facts; refund and dispute retrieval belongs to full-order sync.
            reconciler: new CheckoutSessionReconciler(
                orders: $orders,
                retriever: new CheckoutSessionRetriever($gateway),
                credentialMode: $stripe->secretKeyMode(),
            ),
            retentionPolicy: new RetentionPolicy($configuration->settings()),
            credentialMode: $stripe->secretKeyMode(),
            credentialFingerprint: $stripe->secretKeyFingerprint('checkout-discovery'),
        );
    }

    public function housekeepingRunner(): HousekeepingRunner
    {
        $configuration = $this->configurationReport()->configurationOrFail();
        $stripe = $configuration->stripe();
        $orders = new OrderPageStore($this->kirby);
        $gateway = $this->checkoutSessionGateway();
        $retentionPolicy = new RetentionPolicy($configuration->settings());
        // Housekeeping establishes Checkout/payment outcomes only;
        // full refund and dispute refresh remains the explicit order-sync operation.
        $reconciler = new CheckoutSessionReconciler(
            orders: $orders,
            retriever: new CheckoutSessionRetriever($gateway),
            credentialMode: $stripe->secretKeyMode(),
        );
        $credentialFingerprint = $stripe->secretKeyFingerprint('checkout-discovery');

        return new HousekeepingRunner(
            kirby: $this->kirby,
            orders: $orders,
            orderReferenceSelector: new OrderReferenceSelector($orders),
            stateStore: new HousekeepingStateStore($this->kirby),
            retentionPolicy: $retentionPolicy,
            incompleteOrderRecovery: new IncompleteOrderRecovery(
                orders: $orders,
                gateway: $gateway,
                reconciler: $reconciler,
                retentionPolicy: $retentionPolicy,
                credentialMode: $stripe->secretKeyMode(),
                credentialFingerprint: $credentialFingerprint,
            ),
            checkoutSessionReconciler: $reconciler,
            batchSize: $configuration->housekeeping()->batchSize(),
            credentialFingerprint: $credentialFingerprint,
        );
    }

    public function remoteDiagnostics(): RemoteDiagnostics
    {
        $stripe = $this->configurationReport()
            ->configurationOrFail()
            ->stripe();

        return new RemoteDiagnostics(
            gateway: new StripeApiDiagnosticsGateway($this->stripeClient()),
            store: $this->diagnosticReportStore(),
            configuredMode: $stripe->secretKeyMode(),
            canonicalWebhookUrl: WebhookRoutes::url($this->kirby),
        );
    }

    /** Reading a cached report must not require valid current credentials or construct a Stripe client. */
    public function diagnosticReportStore(): DiagnosticReportStore
    {
        return new DiagnosticReportStore(
            $this->kirby->cache('programmatordev.stripe-checkout.diagnostics'),
        );
    }

    private function products(): ProductConfiguration
    {
        return $this->configurationReport()
            ->configurationOrFail()
            ->products();
    }

    private function shipping(): ShippingConfiguration
    {
        return $this->configurationReport()
            ->configurationOrFail()
            ->shipping();
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

    private function shippingResolver(): ShippingResolverInterface
    {
        $configured = $this->shipping()->resolver();

        return match (true) {
            $configured instanceof ShippingResolverInterface => $configured,
            $configured instanceof Closure => new ClosureShippingResolver($configured),
            default => new ShippingZoneResolver($this->settings()->shippingZones()),
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
        $context = $this->productContext();
        $taxCodeValidator = null;

        return new ProductOptionsFactory(
            configuration: $this->products(),
            context: $context,
            stripePriceResolver: fn(StripePriceReference $reference): StripePrice => $this->productStripePrice($reference),
            taxCodeValidator: function (TaxCode $code) use (&$taxCodeValidator, $context): void {
                // One projection shares a snapshot; later projections read anew.
                // Leave omitted/inactive classification independent of credentials.
                ($taxCodeValidator ??= $this->taxCodeValidator())->validate($code, $context);
            },
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
