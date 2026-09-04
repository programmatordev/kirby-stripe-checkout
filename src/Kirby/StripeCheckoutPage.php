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

    private const SETTING_FIELDS = [
        'pricesource' => 'priceSource',
        'currency' => 'currency',
        'defaultrequiresshipping' => 'defaultRequiresShipping',
    ];

    private const DEFAULT_LANGUAGE_FIELDS = [
        ...self::SETTING_FIELDS,
        'optionpresets' => 'optionPresets',
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

        // Kirby includes its changes-version lock when publishing through the
        // Panel, but removes it again before writing the latest version.
        unset($input['lock']);

        $this->assertProtectedFieldsRemainUnchanged($input);
        $this->assertOnlySettingsFieldsAreUpdated($input, $languageCode);

        $settingInput = array_intersect_key($input, self::SETTING_FIELDS);
        $defaultLanguageInput = array_intersect_key($input, self::DEFAULT_LANGUAGE_FIELDS);

        if ($settingInput !== []) {
            $this->assertSettingUpdates($settingInput);
        }

        $defaultLanguageCode = $this->kirby()->defaultLanguage()?->code();
        $targetLanguageCode = $languageCode ?? $this->kirby()->languageCode();

        if (
            $defaultLanguageInput !== []
            && $defaultLanguageCode !== null
            && $targetLanguageCode !== null
            && $targetLanguageCode !== $defaultLanguageCode
        ) {
            // Non-translatable settings belong to Kirby's default language even
            // when the Panel submits them while another language is active.
            $normalizedDefaultLanguageInput = [];

            foreach ($defaultLanguageInput as $field => $value) {
                unset($input[$field]);
                $normalizedDefaultLanguageInput[self::DEFAULT_LANGUAGE_FIELDS[$field]] = $value;
            }

            $page = $input === []
                ? $this
                : parent::update($input, $targetLanguageCode, $validate);

            return $page->update($normalizedDefaultLanguageInput, $defaultLanguageCode, $validate);
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
    private function assertOnlySettingsFieldsAreUpdated(array $input, ?string $languageCode): void
    {
        $allowed = array_fill_keys(
            array_map('strtolower', array_keys($this->blueprint()->fields())),
            true,
        );
        $stored = $this->version('latest')
            ->content($languageCode ?? 'default')
            ->data();

        foreach ($input as $field => $value) {
            if (
                isset($allowed[$field]) === false
                && in_array($field, self::STRUCTURAL_FIELDS, true) === false
                // Kirby publishes the complete changes version. Preserve
                // unknown legacy fields only when the save does not alter them.
                && (array_key_exists($field, $stored) === false || $stored[$field] !== $value)
            ) {
                throw new PermissionException(
                    message: 'Only plugin-owned Stripe Checkout settings can be updated.',
                );
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function assertSettingUpdates(array $input): void
    {
        $stored = new PageSettings(
            priceSource: $this->fieldValue('priceSource'),
            currency: $this->fieldValue('currency'),
            defaultRequiresShipping: $this->fieldValue('defaultRequiresShipping'),
        );
        $candidate = new PageSettings(
            priceSource: array_key_exists('pricesource', $input)
                ? $input['pricesource']
                : $this->fieldValue('priceSource'),
            currency: array_key_exists('currency', $input)
                ? $input['currency']
                : $this->fieldValue('currency'),
            defaultRequiresShipping: array_key_exists('defaultrequiresshipping', $input)
                ? $input['defaultrequiresshipping']
                : $this->fieldValue('defaultRequiresShipping'),
        );

        /** @var array<string, mixed> $options */
        $options = $this->kirby()->options();
        $settings = (new ConfigurationResolver())
            ->resolve($options)
            ->configurationOrFail()
            ->settings();

        foreach (array_keys($input) as $field) {
            $name = self::SETTING_FIELDS[$field];
            $setting = $settings->setting($name);

            if (
                $setting?->isLocked() === true
                && $candidate->value($name) !== $stored->value($name)
            ) {
                throw new PermissionException(
                    message: 'The Stripe Checkout setting is locked by PHP configuration.',
                );
            }
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
