<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerIdService;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ServerIdServiceTest extends TestCase
{
    public function testAllocationFailsClosedWhenMigrationLockTimesOut(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
        ]);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(
                'SELECT GET_LOCK(?, ?) AS acquired',
                [ServerIdService::MIGRATION_LOCK_NAME, 30],
                false
            )
            ->andReturn((object) ['acquired' => 0]);
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Timed out waiting for the server ID migration lock');

        ServerIdService::nextId();
    }

    public function testAllocationReleasesMigrationLockWhenAllocationFails(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
        ]);

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('hasTable')->once()->with('v2_server_sequence')->andReturn(true);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(
                'SELECT GET_LOCK(?, ?) AS acquired',
                [ServerIdService::MIGRATION_LOCK_NAME, 30],
                false
            )
            ->andReturn((object) ['acquired' => 1])
            ->ordered();
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(
                'SELECT RELEASE_LOCK(?) AS released',
                [ServerIdService::MIGRATION_LOCK_NAME],
                false
            )
            ->andReturn((object) ['released' => 1])
            ->ordered();

        DB::shouldReceive('connection')->times(3)->andReturn($connection);
        DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('allocation failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('allocation failed');

        ServerIdService::nextId();
    }
}
