<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use Kirby\Cms\Page;
use Kirby\Cms\Site;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductNotFoundException;
use Throwable;

/**
 * Resolves eligible published Pages with Kirby's native lookup behavior.
 *
 * @internal
 */
final class KirbyPageLocator
{
    public function find(Site $site, Page|string $reference): Page
    {
        try {
            $page = $reference instanceof Page ? $reference : $site->find($reference);
        } catch (Throwable $error) {
            throw new ProductNotFoundException($error);
        }

        if (
            $page instanceof Page === false
            || $page->isPublished() === false
            || $page->kirby() !== $site->kirby()
        ) {
            throw new ProductNotFoundException();
        }

        return $page;
    }

    public function canonicalReference(Page $page): string
    {
        try {
            $uuid = $page->uuid()->toString();
        } catch (Throwable $error) {
            throw new ProductNotFoundException($error);
        }

        if (str_starts_with($uuid, 'page://') === false) {
            throw new ProductNotFoundException();
        }

        return $uuid;
    }
}
