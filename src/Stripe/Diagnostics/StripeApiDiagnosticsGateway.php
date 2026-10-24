<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Diagnostics;

use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticsErrorCode;
use ProgrammatorDev\StripeCheckout\Diagnostics\Exception\DiagnosticsException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;
use Throwable;

/** Read-only Stripe API boundary for facts used by explicit diagnostics. */
final readonly class StripeApiDiagnosticsGateway implements StripeDiagnosticsGatewayInterface
{
    public function __construct(private StripeClient $client) {}

    public function accountMode(): CredentialMode
    {
        try {
            // The authenticated Balance object exposes the key's actual live/test mode.
            // https://docs.stripe.com/api/balance/balance_retrieve
            $balance = OrderData::map($this->client->balance->retrieve()->toArray());

            return OrderData::boolean($balance['livemode'] ?? null)
                ? CredentialMode::Live
                : CredentialMode::Test;
        } catch (Throwable $error) {
            throw $this->failure($error);
        }
    }

    public function webhookEndpoints(): array
    {
        try {
            $records = [];
            $seenIds = [];
            $startingAfter = null;

            do {
                $parameters = ['limit' => 100];

                if ($startingAfter !== null) {
                    $parameters['starting_after'] = $startingAfter;
                }

                $collection = OrderData::map($this->client->webhookEndpoints->all($parameters)->toArray());
                $items = OrderData::list($collection['data'] ?? null);
                $hasMore = OrderData::boolean($collection['has_more'] ?? null);
                $page = [];

                foreach ($items as $endpoint) {
                    $data = OrderData::map($endpoint);
                    $endpointId = OrderData::nonEmptyString($data['id'] ?? null);

                    if (isset($seenIds[$endpointId])) {
                        throw new OrderDataException();
                    }

                    $seenIds[$endpointId] = true;
                    $page[] = $this->record($data, $endpointId);
                }

                if ($hasMore && $page === []) {
                    throw new OrderDataException();
                }

                foreach ($page as $record) {
                    $records[] = $record;
                    $startingAfter = $record->endpointId();
                }
            } while ($hasMore);

            return $records;
        } catch (DiagnosticsException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw $this->failure($error);
        }
    }

    /** @param array<string, mixed> $data */
    private function record(array $data, string $endpointId): WebhookEndpointRecord
    {
        $enabledEvents = array_map(
            OrderData::nonEmptyString(...),
            OrderData::list($data['enabled_events'] ?? null),
        );
        $apiVersion = OrderData::nullableString($data['api_version'] ?? null);

        if ($apiVersion === '') {
            throw new OrderDataException();
        }

        return new WebhookEndpointRecord(
            endpointId: $endpointId,
            url: OrderData::nonEmptyString($data['url'] ?? null),
            enabledEvents: $enabledEvents,
            liveMode: OrderData::boolean($data['livemode'] ?? null),
            apiVersion: $apiVersion,
            status: OrderData::nonEmptyString($data['status'] ?? null),
        );
    }

    private function failure(Throwable $error): DiagnosticsException
    {
        $errorCode = match (true) {
            $error instanceof AuthenticationException => DiagnosticsErrorCode::AUTHENTICATION_FAILED,
            $error instanceof PermissionException => DiagnosticsErrorCode::PERMISSION_DENIED,
            $error instanceof ApiConnectionException,
            $error instanceof RateLimitException,
            $error instanceof ApiErrorException && ($error->getHttpStatus() === null || $error->getHttpStatus() >= 500) => DiagnosticsErrorCode::REMOTE_UNAVAILABLE,
            $error instanceof ApiErrorException => DiagnosticsErrorCode::REMOTE_REJECTED,
            default => DiagnosticsErrorCode::REMOTE_INCOMPATIBLE,
        };

        return new DiagnosticsException($errorCode, $error);
    }
}
