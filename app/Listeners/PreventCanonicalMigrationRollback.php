<?php

namespace App\Listeners;

use App\Support\LegacyFlowAuthority;
use Illuminate\Database\Events\MigrationStarted;
use ReflectionClass;
use RuntimeException;

class PreventCanonicalMigrationRollback
{
    public function __construct(private LegacyFlowAuthority $legacyFlowAuthority) {}

    public function handle(MigrationStarted $event): void
    {
        if ($event->method !== 'down' || ! $this->legacyFlowAuthority->isAuthoritative()) {
            return;
        }

        $fileName = (new ReflectionClass($event->migration))->getFileName();
        if (! is_string($fileName)) {
            return;
        }

        $migrationName = basename($fileName);
        if (! in_array($migrationName, (array) config('legacy-flow.protected_canonical_migrations'), true)) {
            return;
        }

        throw new RuntimeException("Cannot roll back protected canonical migration [{$migrationName}] after canonical authority has been established.");
    }
}
