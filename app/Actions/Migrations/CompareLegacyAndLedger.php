<?php

namespace App\Actions\Migrations;

use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PredictionScore;
use App\Models\Season;
use App\Models\SeasonPredictionScore;

class CompareLegacyAndLedger
{
    /**
     * @return array{season_id:int,pool_id:?int,members:list<array<string,mixed>>,unapproved_differences:int}
     */
    public function handle(Season $season, bool $allowDraftExclusions = false): array
    {
        $pool = Pool::query()->where('legacy_key', "legacy:season:{$season->id}:pool")->with('members.user')->first();
        if ($pool === null) {
            return [
                'season_id' => $season->id,
                'pool_id' => null,
                'members' => [],
                'unapproved_differences' => 1,
                'error' => 'official_pool_missing',
            ];
        }

        $weekScores = PredictionScore::query()
            ->whereHas('week', fn ($query) => $query->where('season_id', $season->id))
            ->with(['prediction', 'week'])
            ->get();
        $seasonScores = SeasonPredictionScore::query()
            ->where('season_id', $season->id)
            ->with('seasonPrediction')
            ->get();
        $entries = PointEntry::query()
            ->whereIn('pool_member_id', $pool->members->pluck('id'))
            ->where(function ($query): void {
                $query->where('idempotency_key', 'like', 'legacy:%')
                    ->orWhereHas('seasonEventResult', fn ($resultQuery) => $resultQuery->where('legacy_key', 'like', 'legacy:%'));
            })
            ->with(['poolEvent.seasonEvent.round', 'seasonEventResult'])
            ->get();
        $weeks = $season->weeks()->orderBy('number')->get();
        $members = [];
        $unapproved = 0;

        foreach ($pool->members as $member) {
            $userWeekScores = $weekScores->where('user_id', $member->user_id);
            $userSeasonScores = $seasonScores->where('user_id', $member->user_id);
            $memberEntries = $entries->where('pool_member_id', $member->id);
            $legacyRaw = (int) $userWeekScores->sum('points') + (int) $userSeasonScores->sum('points');
            $legacyExpected = (int) $userWeekScores->filter(fn ($score): bool => $score->prediction?->confirmed_at !== null)->sum('points')
                + (int) $userSeasonScores->filter(fn ($score): bool => $score->seasonPrediction?->confirmed_at !== null)->sum('points');
            $ledger = (int) $memberEntries->sum('points');
            $classification = $this->classification($legacyRaw, $legacyExpected, $ledger, $allowDraftExclusions);

            $weekRows = $weeks->map(function ($week) use ($userWeekScores, $memberEntries, $allowDraftExclusions): array {
                $scores = $userWeekScores->where('week_id', $week->id);
                $raw = (int) $scores->sum('points');
                $expected = (int) $scores->filter(fn ($score): bool => $score->prediction?->confirmed_at !== null)->sum('points');
                $ledger = (int) $memberEntries->filter(
                    fn (PointEntry $entry): bool => $entry->poolEvent?->seasonEvent?->round?->legacy_key === "legacy:week:{$week->id}",
                )->sum('points');

                return [
                    'week_id' => $week->id,
                    'week' => __('Week :number', ['number' => $week->number]),
                    'legacy_raw' => $raw,
                    'legacy_expected' => $expected,
                    'ledger' => $ledger,
                    'difference' => $ledger - $expected,
                    'classification' => $this->classification($raw, $expected, $ledger, $allowDraftExclusions),
                ];
            })->all();

            $seasonRaw = (int) $userSeasonScores->sum('points');
            $seasonExpected = (int) $userSeasonScores
                ->filter(fn ($score): bool => $score->seasonPrediction?->confirmed_at !== null)
                ->sum('points');
            $seasonLedger = (int) $memberEntries->filter(
                fn (PointEntry $entry): bool => $entry->poolEvent?->seasonEvent?->round?->legacy_key === "legacy:season:{$season->id}:predictions",
            )->sum('points');
            $seasonRow = [
                'legacy_raw' => $seasonRaw,
                'legacy_expected' => $seasonExpected,
                'ledger' => $seasonLedger,
                'difference' => $seasonLedger - $seasonExpected,
                'classification' => $this->classification($seasonRaw, $seasonExpected, $seasonLedger, $allowDraftExclusions),
            ];
            $rowDifferences = collect([...$weekRows, $seasonRow])
                ->reject(fn (array $row): bool => in_array($row['classification'], ['match', 'approved_draft_exclusion'], true))
                ->count();
            $unapproved += $rowDifferences;

            if ($rowDifferences === 0 && ! in_array($classification, ['match', 'approved_draft_exclusion'], true)) {
                $unapproved++;
            }

            $members[] = [
                'user_id' => $member->user_id,
                'member' => $member->user->name,
                'legacy_raw' => $legacyRaw,
                'legacy_expected' => $legacyExpected,
                'ledger' => $ledger,
                'difference' => $ledger - $legacyExpected,
                'classification' => $classification,
                'weeks' => $weekRows,
                'season' => $seasonRow,
            ];
        }

        return [
            'season_id' => $season->id,
            'pool_id' => $pool->id,
            'members' => $members,
            'unapproved_differences' => $unapproved,
        ];
    }

    private function classification(int $raw, int $expected, int $ledger, bool $allowDraftExclusions): string
    {
        if ($ledger !== $expected) {
            return 'mismatch';
        }

        if ($raw !== $expected) {
            return $allowDraftExclusions ? 'approved_draft_exclusion' : 'draft_exclusion';
        }

        return 'match';
    }
}
