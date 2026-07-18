<?php

namespace Tests;

use App\Actions\Pools\CreatePool;
use App\Actions\Pools\OpenPoolRegistration;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @param array<string, mixed> $data */
    protected function createPoolOpenForRegistration(User $owner, array $data): Pool
    {
        $pool = app(CreatePool::class)->handle($owner, $data);

        return app(OpenPoolRegistration::class)->handle($pool, $owner);
    }
}
