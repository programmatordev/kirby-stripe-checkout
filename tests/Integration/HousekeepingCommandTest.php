<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use Kirby\CLI\CLI;
use League\CLImate\Util\Writer\Buffer;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingCommand;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingRunLock;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;

final class HousekeepingCommandTest extends KirbyTestCase
{
    public function testCommandIsRegistered(): void
    {
        $this->assertSame(
            HousekeepingCommand::class,
            $this->kirby->extension('commands', 'stripe-checkout:housekeeping'),
        );
    }

    public function testReportsSuccessfulEmptyRunAsJson(): void
    {
        $this->withSecretKey();
        [$cli, $output] = $this->cli();

        $exitCode = HousekeepingCommand::execute($cli);
        $result = json_decode($output->get(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($result);
        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['selectedOrders']);
        $this->assertSame(0, $result['processedOrders']);
    }

    public function testReportsConfigurationFailureWithoutPrivateDetails(): void
    {
        [$cli, $output] = $this->cli();

        $exitCode = HousekeepingCommand::execute($cli);
        $result = json_decode($output->get(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($result);
        $this->assertSame(4, $exitCode);
        $this->assertSame([
            'status' => 'failed',
            'errorCode' => 'configuration.credential_missing',
        ], $result);
    }

    public function testReportsLockContentionWithItsOwnExitCode(): void
    {
        $this->withSecretKey();
        [$cli, $output] = $this->cli();

        $exitCode = HousekeepingRunLock::run(
            $this->kirby,
            static fn(): int => HousekeepingCommand::execute($cli),
        );
        $result = json_decode($output->get(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($result);
        $this->assertSame(3, $exitCode);
        $this->assertSame([
            'status' => 'failed',
            'errorCode' => 'housekeeping.locked',
        ], $result);
    }

    public function testReportsPartialRunWithItsOwnExitCode(): void
    {
        $this->withSecretKey();
        $store = new OrderPageStore($this->kirby);
        $createdAt = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $context = new OrderCreationContext(
            uuid: 'order-corrupt',
            orderNumber: 'ORDER-CORRUPT',
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: 'en',
            uiMode: UiMode::Hosted,
            currency: 'EUR',
            lineItems: [OrderFixture::lineItem()],
        );
        $order = $store->create(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create(order: $context, createdAt: $createdAt),
            createdAt: $createdAt,
        );
        $order->version('latest')->update(['schemaVersion' => '2'], 'default');
        [$cli, $output] = $this->cli();

        $exitCode = HousekeepingCommand::execute($cli);
        $result = json_decode($output->get(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($result);
        $this->assertSame(2, $exitCode);
        $this->assertSame('partial_failure', $result['status']);
        $this->assertSame(1, $result['failedOrders']);
        $this->assertSame('housekeeping.partial_failure', $result['errorCode']);
    }

    private function withSecretKey(): void
    {
        $this->kirby = $this->kirby->clone([
            'options' => [
                'programmatordev.stripe-checkout' => [
                    'stripe' => [
                        'secretKey' => 'sk_test_housekeeping',
                    ],
                ],
            ],
        ]);
    }

    /** @return array{CLI, Buffer} */
    private function cli(): array
    {
        $cli = new CLI();
        $output = new Buffer();
        $cli->climate()->output->add('test', $output);
        $cli->climate()->output->defaultTo('test');

        return [$cli, $output];
    }
}
