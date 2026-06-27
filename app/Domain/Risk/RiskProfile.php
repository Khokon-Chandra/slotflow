<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Enums\RiskBand;

/**
 * The result of scoring one booking: a number, a band, and the factors that
 * produced them.
 *
 * @property-read list<RiskFactor> $factors
 */
final readonly class RiskProfile
{
    /**
     * @param  list<RiskFactor>  $factors
     */
    public function __construct(
        public int $score,
        public RiskBand $band,
        public array $factors,
    ) {}

    /**
     * @param  list<RiskFactor>  $factors
     */
    public static function fromFactors(array $factors): self
    {
        $total = array_sum(array_map(fn (RiskFactor $f) => $f->points, $factors));
        $score = max(0, min(100, $total));

        // Show the biggest contributors first — that is the order a human
        // reads them in, and the order the model is asked to explain them in.
        usort($factors, fn (RiskFactor $a, RiskFactor $b) => abs($b->points) <=> abs($a->points));

        return new self($score, RiskBand::fromScore($score), $factors);
    }

    /**
     * @return list<RiskFactor>
     */
    public function increasingFactors(): array
    {
        return array_values(array_filter($this->factors, fn (RiskFactor $f) => $f->points > 0));
    }

    /**
     * @return list<RiskFactor>
     */
    public function reducingFactors(): array
    {
        return array_values(array_filter($this->factors, fn (RiskFactor $f) => $f->points < 0));
    }

    /**
     * @return list<array{code: string, label: string, points: int}>
     */
    public function toArray(): array
    {
        return array_map(fn (RiskFactor $f) => $f->toArray(), $this->factors);
    }
}
