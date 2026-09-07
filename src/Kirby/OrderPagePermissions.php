<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\PagePermissions;

/** @internal Blueprint customization must not remove commerce permissions. */
final class OrderPagePermissions extends PagePermissions
{
    public function can(string $action, bool $default = false): bool
    {
        $kirby = $this->model->kirby();

        return match ($action) {
            // Only the internal impersonation identity may create records;
            // an ordinary administrator must still use the controlled flow.
            'create' => $kirby->user()?->isKirby() === true,
            'read', 'access', 'list' => PluginPermissions::allows($kirby, 'orders.read'),
            'update' => $this->model instanceof OrderPage
                && PluginPermissions::allows($kirby, 'orders.read')
                && PluginPermissions::allows($kirby, 'orders.update')
                && parent::can($action, $default),
            'delete' => $this->model instanceof OrderPage
                && $this->model->isDeletingStoredOrder()
                && $kirby->user()?->isKirby() === true,
            'changeSlug', 'changeStatus', 'changeTemplate', 'changeTitle', 'duplicate', 'move', 'sort', 'preview' => false,
            default => parent::can($action, $default),
        };
    }
}
