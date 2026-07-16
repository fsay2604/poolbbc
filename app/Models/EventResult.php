<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventResult extends Model
{
    /** @use HasFactory<\Database\Factories\EventResultFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id', 'created_by', 'supersedes_id', 'version', 'status', 'correction_reason', 'published_at',
    ];

    protected $attributes = ['status' => 'published'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function selectionRows(): HasMany
    {
        return $this->hasMany(EventResultSelection::class);
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(EventOption::class, 'event_result_selections')->withTimestamps();
    }

    public function pointEntries(): HasMany
    {
        return $this->hasMany(PointEntry::class);
    }
}
