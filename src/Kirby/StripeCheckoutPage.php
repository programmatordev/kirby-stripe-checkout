<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\Page;
use Kirby\Cms\PageBlueprint;
use Kirby\Cms\Site;
use Kirby\Content\Field;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\PageSettings;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;

/**
 * Provides the plugin-owned hub Page and stores editable settings natively.
 *
 * Kirby uses the same Page class for content edited in the Panel and content
 * rendered on the frontend. This model retains Kirby's native content and
 * editing behavior while preventing the record from becoming a public page.
 *
 * @internal
 */
final class StripeCheckoutPage extends Page
{
    public const ID = 'stripe-checkout';
    public const OWNER = 'programmatordev/stripe-checkout';
    public const SCHEMA_VERSION = 1;
    public const TEMPLATE = 'stripe-checkout';

    private const PHP_ONLY_FIELDS = [
        'publishablekey',
        'secretkey',
        'webhooksecret',
    ];

    private const STRUCTURAL_FIELDS = [
        'stripecheckout',
        'title',
        'uuid',
    ];

    private ?string $blueprintContext = null;

    public function blueprint(): PageBlueprint
    {
        // Kirby caches a Page blueprint on the model, but the same model can be
        // reused after impersonation or a locale change during one request.
        $context = implode(':', [
            $this->kirby()->user()?->id() ?? 'guest',
            I18n::locale(),
            PluginPermissions::allows($this->kirby(), 'settings.read') ? '1' : '0',
            PluginPermissions::allows($this->kirby(), 'settings.update') ? '1' : '0',
            PluginPermissions::allows($this->kirby(), 'diagnostics.read') ? '1' : '0',
        ]);

        if ($this->blueprint === null || $this->blueprintContext !== $context) {
            $props = SettingsBlueprint::load($this->kirby());
            $props['model'] = $this;
            $this->blueprint = new PageBlueprint($props);
            $this->blueprintContext = $context;
        }

        return $this->blueprint;
    }

    /** @param array<string, mixed>|null $input */
    public function update(
        ?array $input = null,
        ?string $languageCode = null,
        bool $validate = false,
    ): static {
        /** @var array<string, mixed> $input */
        $input = array_change_key_case($input ?? [], CASE_LOWER);

        $this->assertProtectedFieldsRemainUnchanged($input);
        $this->assertOnlySettingsFieldsAreUpdated($input);

        $hasPriceSource = array_key_exists('pricesource', $input);

        if ($hasPriceSource === true) {
            $this->assertPriceSourceUpdate($input['pricesource']);
        }

        $defaultLanguageCode = $this->kirby()->defaultLanguage()?->code();
        $targetLanguageCode = $languageCode ?? $this->kirby()->languageCode();

        if (
            $hasPriceSource === true
            && $defaultLanguageCode !== null
            && $targetLanguageCode !== null
            && $targetLanguageCode !== $defaultLanguageCode
        ) {
            // Non-translatable settings belong to Kirby's default language even
            // when the Panel submits them while another language is active.
            $priceSource = $input['pricesource'];
            unset($input['pricesource']);

            $page = $input === []
                ? $this
                : parent::update($input, $targetLanguageCode, $validate);

            return $page->update(
                ['priceSource' => $priceSource],
                $defaultLanguageCode,
                $validate,
            );
        }

        return parent::update($input, $languageCode, $validate);
    }

    public function changeSlug(string $slug, ?string $languageCode = null): static
    {
        if ($slug === $this->slug()) {
            return $this;
        }

        throw $this->structuralChangeDenied();
    }

    public function changeStatus(string $status, ?int $position = null): static
    {
        if ($status === 'draft' && $this->isDraft() === true) {
            return $this;
        }

        throw $this->structuralChangeDenied();
    }

    public function changeTemplate(string $template): static
    {
        if ($template === $this->intendedTemplate()->name()) {
            return $this;
        }

        throw $this->structuralChangeDenied();
    }

    public function changeTitle(string $title, ?string $languageCode = null): static
    {
        if ($title === $this->title()->value()) {
            return $this;
        }

        throw $this->structuralChangeDenied();
    }

    public function delete(bool $force = false): bool
    {
        throw $this->structuralChangeDenied();
    }

    /** @param array<string, mixed> $options */
    public function duplicate(?string $slug = null, array $options = []): static
    {
        throw $this->structuralChangeDenied();
    }

    public function move(Site|Page $parent): Page
    {
        if ($this->parentModel()->is($parent) === true) {
            return $this;
        }

        throw $this->structuralChangeDenied();
    }

    /** @param array<string, mixed> $data */
    public function render(
        array $data = [],
        $contentType = 'html',
        \Kirby\Content\VersionId|string|null $versionId = null,
    ): string {
        // The record is editable in the Panel but has no frontend response.
        throw new NotFoundException();
    }

    /** @param array<string, mixed> $input */
    private function assertProtectedFieldsRemainUnchanged(array $input): void
    {
        foreach (self::PHP_ONLY_FIELDS as $field) {
            if (array_key_exists($field, $input) === true) {
                throw new PermissionException(
                    message: 'PHP-only Stripe Checkout configuration cannot be stored in content.',
                );
            }
        }

        foreach (self::STRUCTURAL_FIELDS as $field) {
            if (
                array_key_exists($field, $input) === true
                && $input[$field] !== $this->fieldValue($field)
            ) {
                throw $this->structuralChangeDenied();
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function assertOnlySettingsFieldsAreUpdated(array $input): void
    {
        $allowed = array_fill_keys(
            array_map('strtolower', array_keys($this->blueprint()->fields())),
            true,
        );

        foreach (array_keys($input) as $field) {
            if (
                isset($allowed[$field]) === false
                && in_array($field, self::STRUCTURAL_FIELDS, true) === false
            ) {
                throw new PermissionException(
                    message: 'Only plugin-owned Stripe Checkout settings can be updated.',
                );
            }
        }
    }

    private function assertPriceSourceUpdate(mixed $priceSource): void
    {
        if ($priceSource !== null && is_string($priceSource) === false) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.priceSource',
            );
        }

        // Validate the Page value independently from PHP precedence.
        new PageSettings($priceSource);

        /** @var array<string, mixed> $options */
        $options = $this->kirby()->options();
        $report = (new ConfigurationResolver())->resolve($options);
        $setting = $report->configurationOrFail()
            ->settings()
            ->setting('priceSource');

        if ($setting?->isLocked() !== true) {
            return;
        }

        $storedValue = $this->fieldValue('priceSource');
        $storedValue = $storedValue === '' ? null : $storedValue;

        if ($priceSource !== $storedValue) {
            throw new PermissionException(
                message: 'The Stripe Checkout price source is locked by PHP configuration.',
            );
        }
    }

    private function structuralChangeDenied(): PermissionException
    {
        return new PermissionException(
            message: 'The Stripe Checkout Page structure is protected.',
        );
    }

    private function fieldValue(string $fieldName): mixed
    {
        $field = $this->content(
            $this->kirby()->defaultLanguage()?->code(),
        )->get($fieldName);

        return $field instanceof Field ? $field->value() : null;
    }
}
