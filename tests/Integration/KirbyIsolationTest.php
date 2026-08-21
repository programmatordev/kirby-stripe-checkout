<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class KirbyIsolationTest extends KirbyTestCase
{
    public function testUsesOnlyDisposableApplicationRoots(): void
    {
        foreach ($this->environment->workspace()->roots() as $name => $path) {
            $this->assertSame($path, $this->kirby->root($name));
        }

        $this->assertCount(0, $this->kirby->site()->children());
        $pluginRoot = dirname(__DIR__, 2);

        $this->assertNotSame($pluginRoot . '/content', $this->kirby->root('content'));
        $this->assertNotSame($pluginRoot . '/site', $this->kirby->root('site'));
    }

    public function testFreshEnvironmentCannotSeePreviousPageOrSessionState(): void
    {
        $firstRoot = $this->environment->workspace()->root();

        $this->kirby->site()->createChild([
            'slug' => 'transient-product',
            'template' => 'default',
            'content' => [
                'title' => 'Transient product',
            ],
        ])->changeStatus('listed');
        $this->kirby->session()->data()->set('transient', 'value');

        $this->assertNotNull($this->kirby->site()->find('transient-product'));
        $this->assertSame('value', $this->kirby->session()->data()->get('transient'));

        $this->environment->close();
        $this->assertDirectoryDoesNotExist($firstRoot);

        $this->environment = KirbyTestEnvironment::start();
        $this->kirby = $this->environment->app();

        $this->assertNotSame($firstRoot, $this->environment->workspace()->root());
        $this->assertNull($this->kirby->site()->find('transient-product'));
        $this->assertNull($this->kirby->session()->data()->get('transient'));
    }
}
