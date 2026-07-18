<?php

namespace App\Http\Middleware;

use App\Models\Pool;
use App\Support\LegacyFlowAuthority;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyFlow
{
    public function __construct(private LegacyFlowAuthority $legacyFlowAuthority) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->legacyFlowAuthority->cutoverEnabled()) {
            return $next($request);
        }

        if ($request->routeIs('admin.*')) {
            return redirect()->route('admin.official-rounds', ['migrated' => 1]);
        }

        $user = $request->user();
        $pool = Pool::query()
            ->whereNotNull('legacy_key')
            ->whereHas('season', fn ($query) => $query->where('is_active', true))
            ->when(! $user->is_admin, fn ($query) => $query->whereHas('activeMembers', fn ($memberQuery) => $memberQuery->where('user_id', $user->id)))
            ->latest('id')
            ->first();

        if ($pool === null) {
            return redirect()->route('pools.index', ['migrated' => 1]);
        }

        $route = $request->routeIs('leaderboard', 'predictions.show')
            ? 'pools.leaderboard'
            : 'pools.predictions';

        return new RedirectResponse(route($route, ['pool' => $pool, 'migrated' => 1]));
    }
}
