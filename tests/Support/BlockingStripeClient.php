<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support;

use LogicException;
use Stripe\HttpClient\ClientInterface;

final class BlockingStripeClient implements ClientInterface
{
    /**
     * @param list<string>         $headers
     * @param array<string, mixed> $params
     */
    public function request(
        $method,
        $absUrl,
        $headers,
        $params,
        $hasFile,
        $apiMode = 'v1',
        $maxNetworkRetries = null,
    ): never {
        throw new LogicException(sprintf(
            'Unexpected Stripe HTTP request: %s %s',
            strtoupper((string) $method),
            (string) $absUrl,
        ));
    }
}
