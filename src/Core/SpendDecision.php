<?php

declare(strict_types=1);

namespace KamauPutt\Core;

/** Pure decision record. Never performs a payment or reserves money. */
final readonly class SpendDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
    ) {
    }
}
