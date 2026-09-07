<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\Language;
use Kirby\Content\Version;
use Kirby\Toolkit\BlockCollectionAccess;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;

/**
 * Preserves stored PHP-locked settings through Kirby's native content versions.
 *
 * The Panel's Save action publishes pending `changes` into `latest`; this does
 * not change the Page's visibility. Both stages convert form values, so both
 * versions need the write guard. Permissions and publication remain Kirby-owned.
 *
 * @internal
 */
final class SettingsVersion extends Version
{
    /** @return array<string, string>|null */
    #[BlockCollectionAccess]
    public function read(Language|string $language = 'default'): ?array
    {
        $fields = parent::read($language);

        // A PHP lock may have been added since this pending version was saved.
        // Latest reads stay raw: they are the baseline, not effective PHP values.
        return $fields === null || $this->id->is('changes') === false
            ? $fields
            : $this->preserveLockedSettings($fields, $language);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function prepareFieldsBeforeWrite(array $fields, Language $language): array
    {
        // Native forms include disabled fields and can synthesize defaults for
        // missing toggles. Neither belongs in a locked setting's stored shadow.
        /** @var array<string, mixed> */
        return parent::prepareFieldsBeforeWrite(
            $this->preserveLockedSettings(array_change_key_case($fields, CASE_LOWER), $language),
            $language,
        );
    }

    /**
     * @template T
     * @param array<string, T> $fields
     * @return array<string, T|string>
     */
    private function preserveLockedSettings(array $fields, Language|string $language): array
    {
        $latest = $this->model->version('latest')->read($language);

        // Initial native Page creation may establish blueprint defaults. Once
        // content exists, both pending saves and publication retain its shadows.
        if ($latest === null) {
            return $fields;
        }

        /** @var array<string, mixed> $options */
        $options = $this->model->kirby()->options();
        $settings = (new ConfigurationResolver())->resolve($options)->configurationOrFail()->settings();

        foreach ($settings->all() as $name => $setting) {
            if ($setting->isLocked() === false) {
                continue;
            }

            $field = strtolower($name);
            // Absence is meaningful: removing a PHP override must not expose a
            // value that only came from a disabled field or its form default.
            unset($fields[$field]);

            if (array_key_exists($field, $latest)) {
                $fields[$field] = $latest[$field];
            }
        }

        return $fields;
    }
}
