<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Plugin;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationReport;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\ProductConfiguration;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Product\Internal\ClosureProductResolver;
use ProgrammatorDev\StripeCheckout\Product\Internal\KirbyPageLocator;
use ProgrammatorDev\StripeCheckout\Product\Internal\KirbyPageProductResolver;
use ProgrammatorDev\StripeCheckout\Product\Internal\ProductResolutionService;
use ProgrammatorDev\StripeCheckout\Product\Internal\ProductSelectionViewFactory;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;
use ProgrammatorDev\StripeCheckout\Product\ProductSelection;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionView;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Translation\LocaleResolver;

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

    public function resolveProduct(ProductSelection $selection): ResolvedProduct
    {
        return (new ProductResolutionService($this->productResolver()))->resolve(
            $selection,
            $this->productContext(),
        );
    }

    public function productSelection(Page|string $reference): ProductSelectionView
    {
        $page = (new KirbyPageLocator())->find($this->kirby->site(), $reference);

        return (new ProductSelectionViewFactory($this->products()))->forPage(
            $page,
            $this->kirby->language()?->code(),
        );
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
}
