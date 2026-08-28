<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Exception;

use RuntimeException;

/**
 * Reports one stable, value-safe configuration failure to plugin consumers.
 */
final class ConfigurationException extends RuntimeException
{
    /** @internal Constructed by the configuration boundary. */
    public function __construct(
        private readonly string $configurationErrorCode,
        private readonly ?string $configurationPath = null,
    ) {
        $message = sprintf(
            'Invalid Stripe Checkout configuration (%s)',
            $this->configurationErrorCode,
        );

        if ($this->configurationPath !== null) {
            $message .= sprintf(' at "%s"', $this->configurationPath);
        }

        parent::__construct($message . '.');
    }

    public function errorCode(): string
    {
        return $this->configurationErrorCode;
    }

    public function path(): ?string
    {
        return $this->configurationPath;
    }
}
