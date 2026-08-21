<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support;

use Kirby\Cms\App;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Throwable;

/**
 * Runs the plugin in an isolated Kirby application and restores shared state.
 */
final class KirbyTestEnvironment
{
    private bool $closed = false;

    private function __construct(
        private readonly TestWorkspace $workspace,
        private readonly App $app,
        private readonly ClientInterface $previousStripeClient,
        private readonly string $sessionCookie
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public static function start(): self
    {
        $workspace = TestWorkspace::create();
        $previousStripeClient = ApiRequestor::httpClient();
        $sessionCookie = 'kirby_test_' . bin2hex(random_bytes(8));

        try {
            App::destroy();
            App::$enableWhoops = false;

            $appProperties = [
                'roots' => $workspace->roots(),
                'options' => [
                    'cache' => false,
                    'debug' => false,
                    'session' => [
                        'cookieName' => $sessionCookie,
                        'gcInterval' => false,
                    ],
                    'whoops' => false,
                ],
                'urls' => [
                    'index' => 'https://kirby-stripe-checkout.test',
                ],
            ];

            // The reference translation loader calls option() during plugin
            // registration, so it needs an isolated Kirby context first.
            new App($appProperties);
            require KIRBY_STRIPE_CHECKOUT_ROOT . '/index.php';

            $app = new App($appProperties);

            $app->impersonate('kirby');
            ApiRequestor::setHttpClient(new BlockingStripeClient());
            ApiRequestor::resetTelemetry();

            return new self($workspace, $app, $previousStripeClient, $sessionCookie);
        } catch (Throwable $exception) {
            ApiRequestor::setHttpClient($previousStripeClient);
            ApiRequestor::resetTelemetry();
            App::destroy();
            $workspace->remove();

            throw $exception;
        }
    }

    public function app(): App
    {
        return $this->app;
    }

    public function workspace(): TestWorkspace
    {
        return $this->workspace;
    }

    public function close(): void
    {
        if ($this->closed === true) {
            return;
        }

        try {
            // Destroy the session before removing its root because Kirby can
            // still write session state while the session object is closing.
            $this->app->session()->destroy();
        } finally {
            ApiRequestor::setHttpClient($this->previousStripeClient);
            ApiRequestor::resetTelemetry();
            App::destroy();
            unset($_COOKIE[$this->sessionCookie]);
            $this->workspace->remove();
            $this->closed = true;
        }
    }
}
