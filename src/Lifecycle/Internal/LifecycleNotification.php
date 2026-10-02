<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Lifecycle\Internal;

use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\PaymentAction;

/** @internal Notification intent carried to the protected writer without adding transient actions to canonical order data. */
final readonly class LifecycleNotification
{
    public function __construct(
        private LifecycleEventType $type,
        private ?PaymentAction $nextAction = null,
    ) {}

    public function type(): LifecycleEventType
    {
        return $this->type;
    }

    public function nextAction(): ?PaymentAction
    {
        return $this->nextAction;
    }
}
