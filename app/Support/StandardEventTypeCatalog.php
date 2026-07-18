<?php

namespace App\Support;

use App\Enums\AnswerSource;
use App\Enums\EventMode;
use App\Enums\OfficialRoundTemplate;
use App\Enums\ResultPublicationMode;
use InvalidArgumentException;

final class StandardEventTypeCatalog
{
    public const HEAD_OF_HOUSEHOLD = 'head-of-household';

    public const NOMINATION = 'nomination';

    public const VETO_WINNER = 'veto-winner';

    public const EVICTION = 'eviction';

    public const SEASON_WINNER = 'season-winner';

    /**
     * @return array<string, array{
     *     slug:string,
     *     name:string,
     *     default_mode:EventMode,
     *     answer_source:AnswerSource,
     *     default_config:array<string, mixed>
     * }>
     */
    public function all(): array
    {
        return [
            self::HEAD_OF_HOUSEHOLD => $this->definition(
                self::HEAD_OF_HOUSEHOLD,
                __('Head of Household'),
                __('Who wins Head of Household?'),
                1,
                1,
                1,
                1,
                5,
            ),
            self::NOMINATION => $this->definition(
                self::NOMINATION,
                __('Nomination'),
                __('Who is nominated?'),
                2,
                4,
                2,
                4,
                2,
            ),
            self::VETO_WINNER => $this->definition(
                self::VETO_WINNER,
                __('Power of Veto'),
                __('Who wins the Power of Veto?'),
                1,
                1,
                1,
                1,
                3,
            ),
            self::EVICTION => $this->definition(
                self::EVICTION,
                __('Eviction'),
                __('Who is evicted?'),
                1,
                1,
                0,
                2,
                -2,
            ),
            self::SEASON_WINNER => $this->definition(
                self::SEASON_WINNER,
                __('Season winner'),
                __('Who wins the season?'),
                1,
                1,
                1,
                1,
                10,
            ),
        ];
    }

    /** @return list<string> */
    public function slugs(): array
    {
        return array_keys($this->all());
    }

    public function has(string $slug): bool
    {
        return array_key_exists($slug, $this->all());
    }

    /**
     * @return array{
     *     slug:string,
     *     name:string,
     *     default_mode:EventMode,
     *     answer_source:AnswerSource,
     *     default_config:array<string, mixed>
     * }
     */
    public function find(string $slug): array
    {
        $definition = $this->all()[$slug] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown standard event type [{$slug}].");
        }

        return $definition;
    }

    /**
     * @return list<array{slug:string, overrides:array<string, mixed>}>
     */
    public function composition(OfficialRoundTemplate $template): array
    {
        $standard = [
            $this->compositionItem(self::HEAD_OF_HOUSEHOLD),
            $this->compositionItem(self::NOMINATION, ['name' => __('Nominees')]),
            $this->compositionItem(self::VETO_WINNER),
            $this->compositionItem(self::EVICTION),
        ];

        return match ($template) {
            OfficialRoundTemplate::Standard => $standard,
            OfficialRoundTemplate::NoVeto => array_values(array_filter(
                $standard,
                fn (array $item): bool => $item['slug'] !== self::VETO_WINNER,
            )),
            OfficialRoundTemplate::DoubleEviction => [
                ...array_slice($standard, 0, 3),
                $this->compositionItem(self::EVICTION, [
                    'name' => __('Double eviction'),
                    'prediction_min_selections' => 2,
                    'prediction_max_selections' => 2,
                    'result_min_selections' => 2,
                    'result_max_selections' => 2,
                ]),
            ],
            OfficialRoundTemplate::Finale => [
                $this->compositionItem(self::SEASON_WINNER, ['name' => __('Final winner')]),
            ],
        };
    }

    /** @param array<string, mixed>|null $config */
    public function normalizeDefaultConfig(string $slug, ?array $config): array
    {
        $defaults = $this->find($slug)['default_config'];
        $config ??= [];
        $owner = is_array($config['owner'] ?? null) ? $config['owner'] : [];
        $prediction = is_array($config['prediction'] ?? null) ? $config['prediction'] : [];

        return [
            'question' => array_key_exists('question', $config)
                ? $this->nullableString($config['question'])
                : $defaults['question'],
            'prediction_min_selections' => (int) ($config['prediction_min_selections'] ?? $defaults['prediction_min_selections']),
            'prediction_max_selections' => (int) ($config['prediction_max_selections'] ?? $defaults['prediction_max_selections']),
            'result_min_selections' => (int) ($config['result_min_selections'] ?? $defaults['result_min_selections']),
            'result_max_selections' => (int) ($config['result_max_selections'] ?? $defaults['result_max_selections']),
            'include_inactive_houseguests' => (bool) ($config['include_inactive_houseguests'] ?? $defaults['include_inactive_houseguests']),
            'allow_none' => (bool) ($config['allow_none'] ?? $defaults['allow_none']),
            'result_publication_mode' => ResultPublicationMode::tryFrom((string) ($config['result_publication_mode'] ?? ''))?->value
                ?? $defaults['result_publication_mode'],
            'owner' => [
                'points_per_match' => (int) ($owner['points_per_match'] ?? $defaults['owner']['points_per_match']),
            ],
            'prediction' => [
                'points_per_correct' => (int) ($prediction['points_per_correct'] ?? $defaults['prediction']['points_per_correct']),
                'exact_match_bonus' => (int) ($prediction['exact_match_bonus'] ?? $defaults['prediction']['exact_match_bonus']),
                'wrong_answer_penalty' => (int) ($prediction['wrong_answer_penalty'] ?? $defaults['prediction']['wrong_answer_penalty']),
            ],
            'allow_negative' => (bool) ($config['allow_negative'] ?? $defaults['allow_negative']),
        ];
    }

    /** @param array<string, mixed> $defaultConfig */
    public function scoringConfig(array $defaultConfig): array
    {
        return [
            'owner' => $defaultConfig['owner'],
            'prediction' => $defaultConfig['prediction'],
            'allow_negative' => $defaultConfig['allow_negative'],
        ];
    }

    /**
     * @return array{
     *     slug:string,
     *     name:string,
     *     default_mode:EventMode,
     *     answer_source:AnswerSource,
     *     default_config:array<string, mixed>
     * }
     */
    private function definition(
        string $slug,
        string $name,
        string $question,
        int $predictionMinimum,
        int $predictionMaximum,
        int $resultMinimum,
        int $resultMaximum,
        int $ownerPoints,
    ): array {
        return [
            'slug' => $slug,
            'name' => $name,
            'default_mode' => EventMode::Hybrid,
            'answer_source' => AnswerSource::Houseguests,
            'default_config' => [
                'question' => $question,
                'prediction_min_selections' => $predictionMinimum,
                'prediction_max_selections' => $predictionMaximum,
                'result_min_selections' => $resultMinimum,
                'result_max_selections' => $resultMaximum,
                'include_inactive_houseguests' => false,
                'allow_none' => false,
                'result_publication_mode' => ResultPublicationMode::Immediate->value,
                'owner' => ['points_per_match' => $ownerPoints],
                'prediction' => [
                    'points_per_correct' => 2,
                    'exact_match_bonus' => 0,
                    'wrong_answer_penalty' => 0,
                ],
                'allow_negative' => $ownerPoints < 0,
            ],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function compositionItem(string $slug, array $overrides = []): array
    {
        return ['slug' => $slug, 'overrides' => $overrides];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
