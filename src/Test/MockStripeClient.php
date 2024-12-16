<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use Stripe\HttpClient\ClientInterface;

class MockStripeClient implements ClientInterface
{
    public function __construct(
        private readonly string $body = '{}',
        private readonly int $statusCode = 200,
        private readonly array $headers = []
    ) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1'): array
    {
        return [$this->body, $this->statusCode, $this->headers];
    }
}
