<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Kirby\Plugin\Plugin;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsBlueprint;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPage;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Panel\StripeCheckoutArea;
use ProgrammatorDev\StripeCheckout\StripeCheckout;

// Exercises only the exported package's Composer installation boundary. It is
// invoked by the package job and deliberately kept outside the PHPUnit suites.
$consumerRoot = realpath($argv[1] ?? '');

if ($consumerRoot === false) {
    throw new RuntimeException('The consumer project path does not exist.');
}

$bootstrap = $consumerRoot . '/kirby/bootstrap.php';

if (is_file($bootstrap) === false) {
    throw new RuntimeException('Kirby was not installed in the consumer project.');
}

require $bootstrap;

// Resolve Composer's recorded installation path so the check cannot pass by
// loading plugin code directly from the repository checkout.
$installedPluginRoot = InstalledVersions::getInstallPath(
    'programmatordev/kirby-stripe-checkout',
);
$installedPluginRoot = $installedPluginRoot === null
    ? false
    : realpath($installedPluginRoot);
$expectedPluginRoot = realpath(
    $consumerRoot . '/site/plugins/kirby-stripe-checkout',
);

if ($installedPluginRoot === false || $installedPluginRoot !== $expectedPluginRoot) {
    throw new RuntimeException(
        'Composer did not install the plugin in the expected Kirby plugin directory.',
    );
}

if (is_link($installedPluginRoot) === true) {
    throw new RuntimeException('The consumer must install a mirrored package, not a symlink.');
}

$runtimeRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'kirby-stripe-checkout-consumer-'
    . bin2hex(random_bytes(8));

// Keep the consumer's site root for plugin discovery while redirecting Kirby's
// generated content and runtime state to disposable directories.
$runtimeRoots = [
    'index' => $consumerRoot,
    'content' => $runtimeRoot . '/content',
    'media' => $runtimeRoot . '/media',
    'site' => $consumerRoot . '/site',
    'accounts' => $runtimeRoot . '/accounts',
    'cache' => $runtimeRoot . '/cache',
    'logs' => $runtimeRoot . '/logs',
    'sessions' => $runtimeRoot . '/sessions',
];

$app = null;

try {
    foreach ($runtimeRoots as $name => $path) {
        if ($name !== 'index' && $name !== 'site' && Dir::make($path) !== true) {
            throw new RuntimeException('Unable to create consumer runtime directory: ' . $path);
        }
    }

    App::destroy();
    App::$enableWhoops = false;

    // Do not require the plugin bootstrap here: Kirby must discover the
    // Composer-installed copy through the consumer's site/plugins directory.
    $app = new App([
        'roots' => $runtimeRoots,
        'options' => [
            'cache' => false,
            'debug' => false,
            'session' => [
                'cookieName' => 'kirby_consumer_' . bin2hex(random_bytes(8)),
                'gcInterval' => false,
            ],
            'whoops' => false,
        ],
        'urls' => [
            'index' => 'https://kirby-stripe-checkout-consumer.test',
        ],
    ]);

    $plugin = App::plugin('programmatordev/stripe-checkout');

    if ($plugin instanceof Plugin === false) {
        throw new RuntimeException('Kirby did not discover the installed plugin.');
    }

    if (realpath($plugin->root()) !== $installedPluginRoot) {
        throw new RuntimeException('Kirby loaded the plugin from an unexpected path.');
    }

    $extensions = $plugin->extends();
    $blueprints = $extensions['blueprints'] ?? null;
    $pageModels = $extensions['pageModels'] ?? null;
    $siteMethods = $extensions['siteMethods'] ?? null;
    $areas = $extensions['areas'] ?? null;
    $translations = $extensions['translations'] ?? null;
    $hubBlueprint = is_array($blueprints)
        ? ($blueprints['pages/stripe-checkout'] ?? null)
        : null;

    if (($extensions['options'] ?? null) !== []) {
        throw new RuntimeException('The package registered an unexpected runtime extension.');
    }

    if ($hubBlueprint !== [SettingsBlueprint::class, 'load']) {
        throw new RuntimeException('The package did not register its Stripe Checkout Page blueprint.');
    }

    if (
        is_array($pageModels) === false
        || ($pageModels['stripe-checkout'] ?? null) !== StripeCheckoutPage::class
    ) {
        throw new RuntimeException('The package did not register its Stripe Checkout Page model.');
    }

    if (
        is_array($siteMethods) === false
        || array_keys($siteMethods) !== ['stripeCheckout']
        || is_callable($siteMethods['stripeCheckout']) === false
    ) {
        throw new RuntimeException('The package did not register its Site entry point.');
    }

    if (
        is_array($areas) === false
        || ($areas['stripe-checkout'] ?? null) !== [StripeCheckoutArea::class, 'definition']
    ) {
        throw new RuntimeException('The package did not register its Panel area.');
    }

    if (
        ($extensions['permissions'] ?? null) !== [
            'settings.read' => false,
            'settings.update' => false,
            'diagnostics.read' => false,
        ]
    ) {
        throw new RuntimeException('The package did not register its Panel permissions.');
    }

    if (is_array($translations) === false || array_keys($translations) !== ['en', 'pt_PT']) {
        throw new RuntimeException('The package did not register its bundled translations.');
    }

    foreach ([
        'docs/configuration.md',
        'docs/index.md',
        'docs/panel.md',
        'docs/translations.md',
        'translations/en.php',
        'translations/pt_PT.php',
    ] as $packageFile) {
        if (is_file($installedPluginRoot . '/' . $packageFile) === false) {
            throw new RuntimeException('The installed package is missing ' . $packageFile . '.');
        }
    }

    /** @phpstan-ignore-next-line method.notFound */
    $stripeCheckout = $app->site()->stripeCheckout();

    if ($stripeCheckout instanceof StripeCheckout === false) {
        throw new RuntimeException('The installed Site entry point returned an unexpected value.');
    }

    if ($stripeCheckout->settings()->priceSource() !== PriceSource::Kirby) {
        throw new RuntimeException('The installed package did not resolve its default Settings.');
    }

    $hubPage = (new StripeCheckoutPageStore($app))->page();

    if (
        $hubPage === null
        || $hubPage->id() !== StripeCheckoutPage::ID
        || $hubPage->isDraft() === false
        || $hubPage->intendedTemplate()->name() !== StripeCheckoutPage::TEMPLATE
    ) {
        throw new RuntimeException('The installed package did not initialize its Stripe Checkout Page automatically.');
    }

    fwrite(STDOUT, "Composer consumer smoke test passed.\n");
} finally {
    try {
        // Kirby may still write session state while the session is closing.
        if ($app instanceof App) {
            $app->session()->destroy();
        }
    } finally {
        App::destroy();

        if (is_dir($runtimeRoot) === true && Dir::remove($runtimeRoot) !== true) {
            throw new RuntimeException('Unable to remove the consumer runtime directory.');
        }
    }
}
