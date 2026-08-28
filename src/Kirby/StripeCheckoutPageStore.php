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
final class StripeCheckoutPageStore
{
    public function __construct(
        private readonly App $kirby,
    ) {}

    public function initialize(): StripeCheckoutPage
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
                            'owner' => StripeCheckoutPage::OWNER,
                            'schemaVersion' => StripeCheckoutPage::SCHEMA_VERSION,
                        ]),
                        'title' => 'Stripe Checkout',
                    ],
                    'isDraft' => true,
                    'parent' => null,
                    'site' => $this->kirby->site(),
                    'slug' => StripeCheckoutPage::ID,
                    'template' => StripeCheckoutPage::TEMPLATE,
                ]),
            );
        } catch (Throwable $error) {
            // A concurrent initializer may have completed between lookup and
            // create. Re-read through Kirby before classifying the failure.
            if (($created = $this->find()) !== null) {
                return $this->validate($created);
            }

            throw new ConfigurationException(
                'persistence.write_failed',
                StripeCheckoutPage::ID,
                previous: $error,
            );
        }

        $created = $this->find();

        if ($created === null) {
            throw new ConfigurationException(
                'persistence.verify_failed',
                StripeCheckoutPage::ID,
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

    public function page(): ?StripeCheckoutPage
    {
        $page = $this->find();

        return $page === null ? null : $this->validate($page);
    }

    private function find(): ?Page
    {
        $page = $this->kirby->site()->findPageOrDraft(StripeCheckoutPage::ID);

        // A failed Page::create() can leave Kirby's temporary model in memory.
        // Only persisted Pages can satisfy initialization or diagnostics.
        return $page?->exists() === true ? $page : null;
    }

    private function validate(Page $page): StripeCheckoutPage
    {
        if (
            $page->id() !== StripeCheckoutPage::ID
            || $page->isDraft() === false
            || $page->intendedTemplate()->name() !== StripeCheckoutPage::TEMPLATE
            || $page instanceof StripeCheckoutPage === false
        ) {
            throw new ConfigurationException(
                'persistence.model_mismatch',
                StripeCheckoutPage::ID,
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

        if (($metadata['owner'] ?? null) !== StripeCheckoutPage::OWNER) {
            throw new ConfigurationException(
                'persistence.owner_mismatch',
                StripeCheckoutPage::ID,
            );
        }

        if (($metadata['schemaVersion'] ?? null) !== StripeCheckoutPage::SCHEMA_VERSION) {
            throw new ConfigurationException(
                'persistence.schema_unsupported',
                StripeCheckoutPage::ID,
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
