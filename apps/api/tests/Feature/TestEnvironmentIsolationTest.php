<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestEnvironmentIsolationTest extends TestCase
{
    public function test_test_runtime_is_isolated_from_external_services(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
    }
}
