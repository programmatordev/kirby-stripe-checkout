<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use InvalidArgumentException;
use Kirby\Content\Field as ContentField;
use Kirby\Exception\InvalidArgumentException as KirbyInvalidArgumentException;
use Kirby\Form\FieldClass;

/**
 * Keeps Structure identity and technical data in the default language.
 *
 * Concrete fields provide their own schema adapter and Panel presentation.
 * Secondary-language submissions are always projected back to a label-only
 * overlay, so direct API writes cannot change canonical membership or values.
 * Adapters report neutral PHP argument errors; this Field boundary converts
 * them to Kirby exceptions while other callers can map them differently.
 *
 * @internal
 */
abstract class SynchronizedStructureField extends FieldClass
{
    /** @return list<array<string, mixed>> */
    public function emptyValue(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        /** @var array<string, mixed> $props */
        $props = parent::props();

        return [
            ...$props,
            'serverTechnicalLocked' => $this->technicalLocked(),
            'value' => $this->toFormValue(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function toFormValue(): array
    {
        try {
            $adapter = $this->adapter();

            if ($this->technicalLocked() === false) {
                return $adapter->canonical(parent::toFormValue());
            }

            return $adapter->localized(
                $this->canonicalValue(),
                parent::toFormValue(),
            );
        } catch (InvalidArgumentException $error) {
            throw new KirbyInvalidArgumentException(message: $error->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    public function toStoredValue(): array
    {
        try {
            $adapter = $this->adapter();
            $value = $this->value;

            if ($this->technicalLocked() === false) {
                return $adapter->canonical($value);
            }

            return $adapter->overlay(
                $this->canonicalValue(),
                $value,
            );
        } catch (InvalidArgumentException $error) {
            throw new KirbyInvalidArgumentException(message: $error->getMessage());
        }
    }

    /** @return array<string, callable> */
    public function validations(): array
    {
        return [
            'synchronizedStructure' => function (): bool {
                $this->toStoredValue();

                return true;
            },
        ];
    }

    abstract protected function adapter(): SynchronizedStructureAdapterInterface;

    /** @return list<array<string, mixed>> */
    private function canonicalValue(): array
    {
        $defaultLanguage = $this->kirby()->defaultLanguage();

        if ($defaultLanguage === null) {
            return $this->adapter()->canonical(parent::toFormValue());
        }

        $contentField = $this->model()
            ->content($defaultLanguage->code())
            ->get($this->name());

        return $this->adapter()->canonical(
            $contentField instanceof ContentField ? $contentField->value() : null,
        );
    }

    private function technicalLocked(): bool
    {
        return $this->siblings->language()->isDefault() === false;
    }
}
