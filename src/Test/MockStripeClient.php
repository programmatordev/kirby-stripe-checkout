<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use Stripe\HttpClient\ClientInterface;

readonly class MockStripeClient implements ClientInterface
{
    public function __construct(
        private string $body = '{}',
        private int $statusCode = 200,
        private array $headers = []
    ) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        return [$this->body, $this->statusCode, $this->headers];
    }
}
