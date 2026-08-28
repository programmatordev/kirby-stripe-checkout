<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Data\Yaml;
use ProgrammatorDev\StripeCheckout\Configuration\PageSettings;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use Throwable;

/**
 * Initializes and reads the fixed Panel-managed Page through Kirby's APIs.
 *
 * Keeping the record as a Page lets Kirby provide persistence, permissions,
 * multilingual content handling and its standard Panel editor.
 *
 * @internal
 */
final class SettingsPageStore
{
    public function __construct(
        private readonly App $kirby,
    ) {}

    public function initialize(): SettingsPage
    {
        $existing = $this->find();

        if ($existing !== null) {
            return $this->validate($existing);
        }

        try {
            $this->kirby->impersonate(
                'kirby',
                fn(): Page => Page::create([
                    'content' => [
                        'stripeCheckout' => Yaml::encode([
                            'owner' => SettingsPage::OWNER,
                            'schemaVersion' => SettingsPage::SCHEMA_VERSION,
                        ]),
                        'title' => 'Stripe Checkout Settings',
                    ],
                    'isDraft' => true,
                    'parent' => null,
                    'site' => $this->kirby->site(),
                    'slug' => SettingsPage::ID,
                    'template' => SettingsPage::TEMPLATE,
                ]),
            );
        } catch (Throwable) {
            // A concurrent initializer may have completed between lookup and
            // create. Re-read through Kirby before classifying the failure.
            if (($created = $this->find()) !== null) {
                return $this->validate($created);
            }

            throw new ConfigurationException(
                'persistence.write_failed',
                SettingsPage::ID,
            );
        }

        $created = $this->find();

        if ($created === null) {
            throw new ConfigurationException(
                'persistence.verify_failed',
                SettingsPage::ID,
            );
        }

        return $this->validate($created);
    }

    public function settings(): PageSettings
    {
        $page = $this->find();

        if ($page === null) {
            return new PageSettings();
        }

        $page = $this->validate($page);
        $priceSource = $this->fieldValue($page, 'priceSource');

        if ($priceSource === null || $priceSource === '') {
            return new PageSettings();
        }

        if (is_string($priceSource) === false) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.priceSource',
            );
        }

        return new PageSettings($priceSource);
    }

    public function page(): ?SettingsPage
    {
        $page = $this->find();

        return $page === null ? null : $this->validate($page);
    }

    private function find(): ?Page
    {
        return $this->kirby->site()->findPageOrDraft(SettingsPage::ID);
    }

    private function validate(Page $page): SettingsPage
    {
        if (
            $page->id() !== SettingsPage::ID
            || $page->isDraft() === false
            || $page->intendedTemplate()->name() !== SettingsPage::TEMPLATE
            || $page instanceof SettingsPage === false
        ) {
            throw new ConfigurationException(
                'persistence.model_mismatch',
                SettingsPage::ID,
            );
        }

        try {
            $metadata = Yaml::decode(
                $this->fieldValue($page, 'stripeCheckout'),
            );
        } catch (Throwable) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'stripeCheckout',
            );
        }

        if (($metadata['owner'] ?? null) !== SettingsPage::OWNER) {
            throw new ConfigurationException(
                'persistence.owner_mismatch',
                SettingsPage::ID,
            );
        }

        if (($metadata['schemaVersion'] ?? null) !== SettingsPage::SCHEMA_VERSION) {
            throw new ConfigurationException(
                'persistence.schema_unsupported',
                SettingsPage::ID,
            );
        }

        if (
            count($metadata) !== 2
            || array_diff(array_keys($metadata), ['owner', 'schemaVersion']) !== []
        ) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'stripeCheckout',
            );
        }

        return $page;
    }

    private function fieldValue(Page $page, string $fieldName): mixed
    {
        $field = $page->content(
            $this->kirby->defaultLanguage()?->code(),
        )->get($fieldName);

        return $field instanceof Field ? $field->value() : null;
    }
}
