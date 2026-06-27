<?php

declare(strict_types=1);

namespace App\Enums;

enum RiskBand: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 60 => self::High,
            $score >= 30 => self::Medium,
            default => self::Low,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low risk',
            self::Medium => 'Watch',
            self::High => 'High risk',
        };
    }
}
