<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use JsonSerializable;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptToken;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSubmissionInput;

/**
 * Private form/JSON data for one browser action; creates no Order or Stripe resource.
 * Render it only in browser-specific responses that bypass shared caching.
 */
final readonly class CheckoutBootstrap implements JsonSerializable
{
    /**
     * @internal Issued by the Site API.
     */
    public function __construct(
        private UiMode $uiMode,
        private CheckoutSource $checkoutSource,
        private string $actionUrl,
        private string $csrf,
        private AttemptToken $attemptToken,
        private ?string $cartRevision,
        private ?string $publishableKey,
        private ?CheckoutError $error = null,
        private ?CheckoutSubmissionInput $submissionInput = null,
    ) {}

    public function uiMode(): UiMode
    {
        return $this->uiMode;
    }

    public function checkoutSource(): CheckoutSource
    {
        return $this->checkoutSource;
    }

    public function actionUrl(): string
    {
        return $this->actionUrl;
    }

    /**
     * Keep these fields together for duplicate submissions and retries.
     * JSON sends csrf in X-CSRF and the remaining fields in the body.
     *
     * @return array{
     *     csrf: string,
     *     source: string,
     *     attemptToken: string,
     *     revision?: string,
     *     items?: list<array{reference: string, quantity: int, options?: array<string, string>}>,
     *     shippingCountry?: string
     * }
     */
    public function submissionData(): array
    {
        if ($this->submissionInput !== null) {
            return [
                'csrf' => $this->csrf,
                ...$this->submissionInput->toFormData(),
            ];
        }

        $submissionData = [
            'csrf' => $this->csrf,
            'source' => $this->checkoutSource->value,
            'attemptToken' => $this->attemptToken->value(),
        ];

        if ($this->cartRevision !== null) {
            $submissionData['revision'] = $this->cartRevision;
        }

        return $submissionData;
    }

    public function publishableKey(): ?string
    {
        return $this->publishableKey;
    }

    public function error(): ?CheckoutError
    {
        return $this->error;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'uiMode' => $this->uiMode->value,
            'source' => $this->checkoutSource->value,
            'actionUrl' => $this->actionUrl,
            'submissionData' => $this->submissionData(),
        ];

        if ($this->uiMode === UiMode::Embedded) {
            $data['publishableKey'] = $this->publishableKey;
        }

        if ($this->error !== null) {
            $data['error'] = $this->error->toArray();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
