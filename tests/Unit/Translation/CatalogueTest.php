<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Translation;

use Kirby\Toolkit\I18n;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;

final class CatalogueTest extends TestCase
{
    public function testBundledCataloguesUseTheNamespaceAndHaveExactParity(): void
    {
        $catalogues = Catalogue::bundled();

        $this->assertSame(['en', 'pt_PT'], array_keys($catalogues));
        $this->assertNotEmpty($catalogues['en']);
        $this->assertSame(array_keys($catalogues['en']), array_keys($catalogues['pt_PT']));

        foreach (array_keys($catalogues['en']) as $key) {
            $this->assertStringStartsWith(Catalogue::PREFIX, $key);
        }
    }

    public function testProjectOverridesAndAdditionalLocalesUseEnglishFallback(): void
    {
        $environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'translations' => [
                    'fr' => ['area.label' => 'Paiement Stripe'],
                    'pt' => ['tabs.settings' => 'Configuração'],
                ],
            ],
        ]);

        try {
            $environment->app()->setCurrentTranslation('pt');
            $this->assertSame(
                'Configuração',
                I18n::translate(Catalogue::PREFIX . 'tabs.settings'),
            );

            $environment->app()->setCurrentTranslation('fr');
            $this->assertSame(
                'Paiement Stripe',
                I18n::translate(Catalogue::PREFIX . 'area.label'),
            );
            $this->assertSame(
                'Settings',
                I18n::translate(Catalogue::PREFIX . 'tabs.settings'),
            );
        } finally {
            $environment->close();
        }
    }

    public function testUnknownSuffixIsRejectedWithASafePath(): void
    {
        $report = (new ConfigurationResolver())->resolve([
            'programmatordev.stripe-checkout' => [
                'translations' => [
                    'en' => ['settings.typo' => 'Typo'],
                ],
            ],
        ]);
        $error = $report->error();

        $this->assertInstanceOf(ConfigurationException::class, $error);
        $this->assertSame('configuration.translation_invalid', $error->errorCode());
        $this->assertSame('translations.en.settings.typo', $error->path());
    }
}
