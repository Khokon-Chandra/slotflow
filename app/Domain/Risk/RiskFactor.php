<?php

declare(strict_types=1);

namespace App\Domain\Risk;

/**
 * One reason a booking scored the way it did.
 *
 * Every factor carries the points it contributed, so the score is never a
 * bare number: the admin panel shows the arithmetic, and a customer who asks
 * "why was I asked for a deposit?" gets a real answer.
 */
final readonly class RiskFactor
{
    public function __construct(
        public string $code,
        public string $label,
        public int $points,
    ) {}

    /**
     * @return array{code: string, label: string, points: int}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'points' => $this->points,
        ];
    }
}
