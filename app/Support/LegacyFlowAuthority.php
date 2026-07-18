<?php

namespace App\Support;

use App\Models\CanonicalFlowAuthority;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class LegacyFlowAuthority
{
    public function isAuthoritative(): bool
    {
        if (! Schema::hasTable('canonical_flow_authorities')) {
            return config('legacy-flow.canonical_is_authoritative') === true;
        }

        if (config('legacy-flow.canonical_is_authoritative') === true) {
            $this->activate();

            return true;
        }

        return CanonicalFlowAuthority::query()
            ->whereKey(CanonicalFlowAuthority::MARKER)
            ->exists();
    }

    public function cutoverEnabled(): bool
    {
        $authoritative = $this->isAuthoritative();

        return config('legacy-flow.cutover_enabled') === true || $authoritative;
    }

    public function activate(): CanonicalFlowAuthority
    {
        if (! Schema::hasTable('canonical_flow_authorities')) {
            throw new RuntimeException('The canonical flow authority marker table has not been migrated.');
        }

        return CanonicalFlowAuthority::query()->firstOrCreate([
            'marker' => CanonicalFlowAuthority::MARKER,
        ]);
    }

    public function marker(): ?CanonicalFlowAuthority
    {
        if (! Schema::hasTable('canonical_flow_authorities')) {
            return null;
        }

        return CanonicalFlowAuthority::query()->find(CanonicalFlowAuthority::MARKER);
    }
}
