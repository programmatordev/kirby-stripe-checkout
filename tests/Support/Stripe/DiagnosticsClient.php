<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support\Stripe;

use LogicException;
use Stripe\HttpClient\ClientInterface;

/** Offline Stripe responses for the explicit diagnostics gateway. */
final class DiagnosticsClient implements ClientInterface
{
    public bool $liveMode = false;

    public int $httpStatus = 200;

    /** @var array<string, array<string, mixed>> */
    public array $webhookPages = [];

    /** @var list<array{url: string, parameters: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param list<string>         $headers
     * @param array<string, mixed> $params
     * @return array{string, int, array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        if ($method !== 'get') {
            throw new LogicException('Diagnostics tests permit read-only Stripe requests.');
        }

        $this->requests[] = [
            'url' => $absUrl,
            'parameters' => $params,
        ];

        if ($this->httpStatus !== 200) {
            return [json_encode(['error' => [
                'message' => 'PRIVATE_DIAGNOSTICS_CANARY',
                'type' => 'invalid_request_error',
            ]], JSON_THROW_ON_ERROR), $this->httpStatus, ['request-id' => 'req_diagnostics_failure']];
        }

        $path = parse_url($absUrl, PHP_URL_PATH);

        if ($path === '/v1/balance') {
            $body = [
                'object' => 'balance',
                'livemode' => $this->liveMode,
                'available' => [],
                'pending' => [],
            ];
        } elseif ($path === '/v1/webhook_endpoints') {
            if (($params['limit'] ?? null) !== 100) {
                throw new LogicException('Webhook endpoint discovery must use full pages.');
            }

            $startingAfter = $params['starting_after'] ?? '';

            if (is_string($startingAfter) === false) {
                throw new LogicException('Webhook pagination cursor must be a string.');
            }

            $body = $this->webhookPages[$startingAfter] ?? [
                'object' => 'list',
                'has_more' => false,
                'data' => [],
            ];
        } else {
            throw new LogicException('No diagnostics fixture exists for this Stripe read.');
        }

        return [json_encode($body, JSON_THROW_ON_ERROR), 200, ['request-id' => 'req_diagnostics']];
    }
}
