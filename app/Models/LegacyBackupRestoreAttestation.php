<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LegacyBackupRestoreAttestation extends Model
{
    /** @use HasFactory<\Database\Factories\LegacyBackupRestoreAttestationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'authority_marker',
        'environment',
        'restore_target',
        'backup_sha256',
        'evidence_path',
        'evidence_sha256',
        'integrity_checks',
        'attested_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (LegacyBackupRestoreAttestation $attestation): void {
            $attestation->verified_at = now();
            $attestation->created_at = now();
        });

        static::updating(function (): never {
            throw new LogicException('Legacy backup restore attestations are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Legacy backup restore attestations cannot be removed.');
        });
    }

    protected function casts(): array
    {
        return [
            'integrity_checks' => 'array',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function authority(): BelongsTo
    {
        return $this->belongsTo(CanonicalFlowAuthority::class, 'authority_marker', 'marker');
    }
}
