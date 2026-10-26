<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

/** @internal Preserves nested Checkout submission data through PHP-style bracketed form field names. */
final class SubmissionFieldsFormatter
{
    /**
     * @param array<string, mixed> $submissionData
     * @return array<string, string>
     */
    public function format(array $submissionData): array
    {
        $submissionFields = [];

        foreach ($submissionData as $name => $value) {
            $this->append($submissionFields, $name, $value);
        }

        return $submissionFields;
    }

    /**
     * @param array<string, string> $submissionFields
     */
    private function append(array &$submissionFields, string $name, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $nestedValue) {
                $this->append($submissionFields, $name . '[' . $key . ']', $nestedValue);
            }

            return;
        }

        /** @var string|int $value CheckoutBootstrap submission data has scalar leaves. */
        $submissionFields[$name] = (string) $value;
    }
}
