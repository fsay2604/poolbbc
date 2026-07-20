<?php

namespace App\Support;

use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PredictionStatus;
use Carbon\CarbonImmutable;

final readonly class PredictionEventView
{
    /**
     * @param  list<array{id: int, label: string}>  $options
     * @param  list<int>  $selectedOptionIds
     * @param  list<string>  $resultOptionLabels
     * @param  list<array{member_name: string, option_labels: list<string>}>  $revealedPredictions
     */
    public function __construct(
        public string $key,
        public string $source,
        public string $sourceLabel,
        public int $sourceId,
        public string $roundKey,
        public string $roundName,
        public int $roundPosition,
        public int $eventPosition,
        public string $name,
        public ?string $question,
        public EventMode $mode,
        public EventStatus $effectiveStatus,
        public string $effectiveStatusLabel,
        public ?CarbonImmutable $locksAt,
        public ?string $deadlineLabel,
        public int $minimumSelections,
        public int $maximumSelections,
        public string $selectionLimitLabel,
        public array $options,
        public array $selectedOptionIds,
        public ?PredictionStatus $predictionStatus,
        public string $progressState,
        public string $progressLabel,
        public string $progressColor,
        public bool $isSingleSelection,
        public bool $canSave,
        public bool $canSubmit,
        public string $submitLabel,
        public bool $showResult,
        public string $resultLabel,
        public bool $hasPublishedResult,
        public array $resultOptionLabels,
        public array $revealedPredictions,
    ) {}
}
