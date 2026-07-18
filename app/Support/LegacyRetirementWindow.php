<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

class LegacyRetirementWindow
{
    public const MINIMUM_OBSERVATION_DAYS = 14;

    public function __construct(private LegacyFlowAuthority $legacyFlowAuthority) {}

    public function minimumDays(): int
    {
        return max(
            self::MINIMUM_OBSERVATION_DAYS,
            (int) config('legacy-flow.minimum_observation_days'),
        );
    }

    public function effectiveStart(): ?Carbon
    {
        $configuredStart = $this->parsePastTimestamp(config('legacy-flow.observation_started_at'));
        $activatedAt = $this->legacyFlowAuthority->marker()?->activated_at;

        if ($configuredStart === null || $activatedAt === null) {
            return null;
        }

        return $configuredStart->greaterThan($activatedAt)
            ? $configuredStart
            : $activatedAt->copy();
    }

    public function completion(): ?Carbon
    {
        return $this->effectiveStart()?->addDays($this->minimumDays());
    }

    public function observedDays(): int
    {
        $effectiveStart = $this->effectiveStart();

        return $effectiveStart === null
            ? 0
            : (int) floor($effectiveStart->diffInDays(now()));
    }

    private function parsePastTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        try {
            $timestamp = Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $timestamp->isFuture() ? null : $timestamp;
    }
}
