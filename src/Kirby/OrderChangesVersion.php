<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\Language;
use Kirby\Content\Version;
use Kirby\Toolkit\BlockCollectionAccess;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;

/** @internal Native editing version with live, read-only order facts. */
final class OrderChangesVersion extends Version
{
    /** @return array<string, string>|null */
    #[BlockCollectionAccess]
    public function read(Language|string $language = 'default'): ?array
    {
        $fields = parent::read($language);

        if ($fields === null) {
            return null;
        }

        // Kirby publishes the complete changes version, including disabled
        // fields. Its saved copy must not roll back or conflict with newer facts.
        $customFields = array_filter(
            $fields,
            static fn(string $field): bool => OrderSchema::isReserved($field) === false,
            ARRAY_FILTER_USE_KEY,
        );
        // VersionCache belongs to the model instance. A canonical write through
        // another Page cannot refresh this retained model's cached latest values.
        /** @var OrderPage $model */
        $model = $this->model;
        $page = (new OrderPageStore($model->kirby()))->requirePage($model->id());
        $latest = $page->version('latest')->read($language) ?? [];
        $protectedFields = array_filter(
            $latest,
            OrderSchema::isReserved(...),
            ARRAY_FILTER_USE_KEY,
        );

        // Read the same language; Kirby's content() supplies default-language
        // fallback without copying canonical fields into a translation.
        return [...$customFields, ...$protectedFields];
    }
}
