<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Event;
use Kirby\Cms\Events;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\InvalidSessionRequestException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestFactoryInterface;
use Throwable;

/** Applies additive filters, then the advanced factory, before auditing against the standard request. */
final class SessionRequestCustomizer
{
    public const FILTER = 'programmatordev.stripe-checkout.session.parameters';

    public function __construct(
        private readonly App $kirby,
        private readonly SessionRequestValidator $validator = new SessionRequestValidator(),
    ) {}

    public function customize(
        SessionRequestContext $context,
        SessionRequest $request,
        SessionRequestFactoryInterface|Closure|null $factory = null,
    ): SessionRequest {
        $hasFilter = (new Events($this->kirby))->hooks(new Event(self::FILTER)) !== [];
        $customizedRequest = $request;

        if ($hasFilter) {
            try {
                $additions = $this->kirby->apply(self::FILTER, [
                    'parameters' => [],
                    'context' => $context,
                ], 'parameters');
            } catch (Throwable $error) {
                throw new InvalidSessionRequestException(
                    'session_request.filter_failed',
                    previous: $error,
                );
            }

            if (is_array($additions) === false || ($additions !== [] && array_is_list($additions))) {
                throw new InvalidSessionRequestException('session_request.additions_invalid');
            }

            $customizedRequest = $this->validator->validateAdditions($request, $additions);
        }

        if ($factory !== null) {
            try {
                $customizedRequest = $factory instanceof SessionRequestFactoryInterface
                    ? $factory->create($context, $customizedRequest)
                    : $factory($context, $customizedRequest);
            } catch (Throwable $error) {
                throw new InvalidSessionRequestException(
                    'session_request.factory_failed',
                    previous: $error,
                );
            }

            if ($customizedRequest instanceof SessionRequest === false) {
                throw new InvalidSessionRequestException('session_request.factory_invalid');
            }
        }

        return $this->validator->validate($request, $customizedRequest);
    }
}
