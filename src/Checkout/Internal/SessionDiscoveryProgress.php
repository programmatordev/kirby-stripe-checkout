<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionDiscoveryPage;
use Throwable;

/**
 * Immutable continuation for one order's search under one credential and creation window.
 * The candidate remains provisional until complete enumeration and purchase reconciliation.
 * Completion describes the listing only; an empty search does not prove creation failed.
 *
 * @internal
 */
final readonly class SessionDiscoveryProgress
{
    private const INVALID_PROGRESS_MESSAGE = 'Invalid Checkout Session discovery progress.';

    public function __construct(
        public string $pageUuid,
        public string $credentialFingerprint,
        public int $createdFrom,
        public int $createdBefore,
        public ?string $startingAfter = null,
        public ?string $candidateSessionId = null,
        public bool $complete = false,
    ) {
        OrderData::uuid($pageUuid);

        if ($createdFrom < 0 || $createdBefore <= $createdFrom) {
            throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
        }

        if ($credentialFingerprint === '') {
            throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
        }

        if ($startingAfter === '') {
            throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
        }

        if ($candidateSessionId === '') {
            throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
        }

        if ($complete && $startingAfter !== null) {
            throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
        }
    }

    public function advance(CheckoutSessionDiscoveryPage $page): self
    {
        $candidateSessionId = $this->candidateSessionId;

        foreach ($page->sessionIds as $sessionId) {
            if ($candidateSessionId !== null && $candidateSessionId !== $sessionId) {
                // Ownership metadata alone cannot choose between two Sessions for the same attempt.
                throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
            }

            $candidateSessionId = $sessionId;
        }

        return new self(
            pageUuid: $this->pageUuid,
            credentialFingerprint: $this->credentialFingerprint,
            createdFrom: $this->createdFrom,
            createdBefore: $this->createdBefore,
            startingAfter: $page->nextCursor,
            candidateSessionId: $candidateSessionId,
            complete: $page->nextCursor === null,
        );
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'pageUuid' => $this->pageUuid,
            'credentialFingerprint' => $this->credentialFingerprint,
            'createdFrom' => $this->createdFrom,
            'createdBefore' => $this->createdBefore,
            'startingAfter' => $this->startingAfter,
            'candidateSessionId' => $this->candidateSessionId,
            'complete' => $this->complete,
        ];
    }

    public static function fromArray(mixed $data): self
    {
        $keys = [
            'pageUuid',
            'credentialFingerprint',
            'createdFrom',
            'createdBefore',
            'startingAfter',
            'candidateSessionId',
            'complete',
        ];

        if (is_array($data) === false) {
            throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
        }

        if (array_diff(array_keys($data), $keys) !== []) {
            throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
        }

        if (array_diff($keys, array_keys($data)) !== []) {
            throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
        }

        try {
            $pageUuid = $data['pageUuid'];
            $credentialFingerprint = $data['credentialFingerprint'];
            $startingAfter = $data['startingAfter'];
            $candidateSessionId = $data['candidateSessionId'];
            $complete = $data['complete'];

            if (is_string($pageUuid) === false) {
                throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
            }

            if (is_string($credentialFingerprint) === false) {
                throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
            }

            if ($startingAfter !== null && is_string($startingAfter) === false) {
                throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
            }

            if ($candidateSessionId !== null && is_string($candidateSessionId) === false) {
                throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
            }

            if (is_bool($complete) === false) {
                throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE);
            }

            return new self(
                pageUuid: $pageUuid,
                credentialFingerprint: $credentialFingerprint,
                createdFrom: OrderData::integer($data['createdFrom']),
                createdBefore: OrderData::integer($data['createdBefore']),
                startingAfter: $startingAfter,
                candidateSessionId: $candidateSessionId,
                complete: $complete,
            );
        } catch (Throwable $error) {
            throw new InvalidArgumentException(self::INVALID_PROGRESS_MESSAGE, previous: $error);
        }
    }
}
