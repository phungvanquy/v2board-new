<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\ApiException;
use App\Services\DatabaseTransferService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseTransferServiceTest extends TestCase
{
    private DatabaseTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DatabaseTransferService::class);
        // Use local disk for tests
        Storage::fake('local');
    }

    public function testValidateUploadAcceptsSqlFile(): void
    {
        $file = UploadedFile::fake()->create('dump.sql', 100);
        $result = $this->service->validateUpload($file);
        $this->assertSame(DatabaseTransferService::KIND_SQL, $result['kind']);
        $this->assertFalse($result['is_gz']);
        $this->assertEquals(100 * 1024, $result['size']); // UploadedFile::fake() sizes in KB
    }

    public function testValidateUploadAcceptsSqlGzFile(): void
    {
        $file = UploadedFile::fake()->create('dump.sql.gz', 50);
        $result = $this->service->validateUpload($file);
        $this->assertSame(DatabaseTransferService::KIND_SQL_GZ, $result['kind']);
        $this->assertTrue($result['is_gz']);
    }

    public function testValidateUploadAcceptsFullBundleFile(): void
    {
        foreach (['backup.tar.gz', 'backup.tgz'] as $name) {
            $file = UploadedFile::fake()->create($name, 50);
            $result = $this->service->validateUpload($file);
            $this->assertSame(DatabaseTransferService::KIND_BUNDLE, $result['kind']);
            $this->assertTrue($result['is_gz']);
        }
    }

    public function testValidateUploadRejectsInvalidExtension(): void
    {
        $file = UploadedFile::fake()->create('dump.txt', 100);
        $this->expectException(ApiException::class);
        $this->service->validateUpload($file);
    }

    public function testValidateUploadRejectsOversizedFile(): void
    {
        // Config default is 512 MB, so use > 512
        $file = UploadedFile::fake()->create('dump.sql', 513 * 1024 * 1024);
        $this->expectException(ApiException::class);
        $this->service->validateUpload($file);
    }

    public function testValidateUploadRejectsEmptyFile(): void
    {
        $file = UploadedFile::fake()->create('dump.sql', 0);
        $this->expectException(ApiException::class);
        $this->service->validateUpload($file);
    }

    public function testBuildDumpCommandUsesEscapedArgs(): void
    {
        // CI has no .env, so pin the mysql connection tokens to known values.
        config([
            'database.connections.mysql.host' => 'db',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'v2board',
            'database.connections.mysql.username' => 'v2board',
            'database.connections.mysql.password' => 'secret',
        ]);

        $dump = $this->service->buildDumpCommand(null);
        $cmd = $dump['cmd'];

        // Verify escaping — args should be quoted
        $this->assertStringContainsString("'db'", $cmd); // host
        $this->assertStringContainsString("'3306'", $cmd); // port
        $this->assertStringContainsString("'v2board'", $cmd); // username and database
        $this->assertStringContainsString('--single-transaction', $cmd);
        $this->assertStringContainsString('--no-tablespaces', $cmd);
        // Should NOT contain --set-gtid-purged=OFF (MariaDB compat)
        $this->assertStringNotContainsString('--set-gtid-purged', $cmd);
        // The password must not leak into the command line.
        $this->assertStringNotContainsString('secret', $cmd);
    }

    public function testBuildDumpCommandIncludesPasswordInEnv(): void
    {
        config(['database.connections.mysql.password' => 'secret']);

        $dump = $this->service->buildDumpCommand(null);
        $this->assertArrayHasKey('MYSQL_PWD', $dump['env']);
        $this->assertSame('secret', $dump['env']['MYSQL_PWD']);
    }

    public function testBuildDumpCommandOmitsPasswordWhenEmpty(): void
    {
        config(['database.connections.mysql.password' => '']);

        $dump = $this->service->buildDumpCommand(null);
        $this->assertArrayNotHasKey('MYSQL_PWD', $dump['env']);
    }

    public function testDumpNeverCarriesAuditTableData(): void
    {
        // Restoring a dump that contains the audit table's INSERTs would delete the
        // very history that records the restore. The dump must ignore its data.
        $cmd = $this->service->buildDumpCommand(null)['cmd'];
        // The value is escapeshellarg'd as "<db>.<table>", so match the table suffix.
        $this->assertStringContainsString(
            DatabaseTransferService::AUDIT_TABLE,
            $cmd
        );
        $this->assertMatchesRegularExpression(
            '/--ignore-table=.*' . preg_quote(DatabaseTransferService::AUDIT_TABLE, '/') . '/',
            $cmd
        );
    }

    public function testAuditTableDdlIsIdempotentWhenDbAvailable(): void
    {
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No database available: ' . $e->getMessage());
        }

        $ddl = $this->service->auditTableDdl();
        $this->assertNull($ddl['error']);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', (string) $ddl['ddl']);
        // Structure-only: a restored dump must never DROP or reload the history table.
        $this->assertStringNotContainsString('DROP TABLE', (string) $ddl['ddl']);
        $this->assertStringNotContainsString('AUTO_INCREMENT=', (string) $ddl['ddl']);
    }

    public function testBuildImportCommandPlainSql(): void
    {
        $import = $this->service->buildImportCommand('/tmp/dump.sql', false);
        $cmd = $import['cmd'];

        $this->assertStringContainsString('mysql', $cmd);
        $this->assertStringContainsString('/tmp/dump.sql', $cmd);
        $this->assertStringNotContainsString('gzip', $cmd);
    }

    public function testBuildImportCommandGz(): void
    {
        $import = $this->service->buildImportCommand('/tmp/dump.sql.gz', true);
        $cmd = $import['cmd'];

        $this->assertStringContainsString('gzip -dc', $cmd);
        $this->assertStringContainsString('/tmp/dump.sql.gz', $cmd);
        $this->assertStringContainsString('|', $cmd);
    }

    public function testIsCliAvailableMatchesInstalledBinary(): void
    {
        // The result depends on whether mysqldump exists where the tests run
        // (present in the app image via mariadb-client, absent in php:*-ci).
        $out = [];
        $code = null;
        @exec('command -v mysqldump 2>/dev/null', $out, $code);

        $this->assertEquals($code === 0, $this->service->isCliAvailable());
    }

    public function testExportFilenameIncludesDatabaseNameAndTimestamp(): void
    {
        $filename = $this->service->exportFilename(false);
        $this->assertStringContainsString('v2board_', $filename);
        $this->assertStringEndsWith('.sql', $filename);
    }

    public function testExportFilenameGzSuffix(): void
    {
        $filename = $this->service->exportFilename(true);
        $this->assertStringEndsWith('.sql.gz', $filename);
    }

    public function testAcquireRestoreLockReturnsTrueOnce(): void
    {
        $acquired = $this->service->acquireRestoreLock();
        $this->assertTrue($acquired);

        // Second attempt should fail (lock already held)
        $acquired2 = $this->service->acquireRestoreLock();
        $this->assertFalse($acquired2);

        // After release, should succeed
        $this->service->releaseRestoreLock();
        $acquired3 = $this->service->acquireRestoreLock();
        $this->assertTrue($acquired3);
    }

    public function testIsRestoreLockedWorks(): void
    {
        $this->assertFalse($this->service->isRestoreLocked());

        $this->service->acquireRestoreLock();
        $this->assertTrue($this->service->isRestoreLocked());

        $this->service->releaseRestoreLock();
        $this->assertFalse($this->service->isRestoreLocked());
    }

    public function testStoreUploadSavesFileAndReturnsPath(): void
    {
        $file = UploadedFile::fake()->create('dump.sql', 100);
        $path = $this->service->storeUpload($file, DatabaseTransferService::KIND_SQL);

        $this->assertTrue(file_exists($path));
        $this->assertStringContainsString('import_', $path);
        $this->assertStringEndsWith('.sql', $path);
    }

    public function testAssertLooksLikeSqlRejectsEmptyFile(): void
    {
        // Create an empty temp file
        $path = tempnam(sys_get_temp_dir(), 'test_');

        $this->expectException(ApiException::class);
        $this->service->assertLooksLikeSql($path, false);

        unlink($path);
    }

    public function testAssertLooksLikeSqlRejectsNonSqlContent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($path, 'this is not sql content at all');

        $this->expectException(ApiException::class);
        $this->service->assertLooksLikeSql($path, false);

        unlink($path);
    }

    public function testAssertLooksLikeSqlAcceptsSqlMarkers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($path, '-- SQL dump
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;');

        $this->service->assertLooksLikeSql($path, false);
        $this->assertTrue(true);

        unlink($path);
    }

    public function testBundleRejectsTruncatedOrMissingEntries(): void
    {
        // Truncated gzip: assertBundleIntegrity must fail on the gzip CRC.
        $raw = tempnam(sys_get_temp_dir(), 'bundle_gz');
        $gz = gzopen($raw, 'wb9');
        gzwrite($gz, 'header');
        gzclose($gz);
        $bytes = (string) file_get_contents($raw);
        unlink($raw);
        $trunc = tempnam(sys_get_temp_dir(), 'bundle_trunc');
        file_put_contents($trunc, substr($bytes, 0, -6));

        try {
            $this->expectException(ApiException::class);
            $this->service->assertBundleIntegrity($trunc);
        } finally {
            @unlink($trunc);
        }
    }

    public function testValidBundlePassesIntegrity(): void
    {
        // Prefer the host tar when available; otherwise skip (php:8.2-ci may lack one).
        $tmp = tempnam(sys_get_temp_dir(), 'bundle_ok_');
        @unlink($tmp);
        $dir = $tmp . '_d';
        mkdir($dir);
        file_put_contents($dir . '/dump.sql', "CREATE TABLE `t` (`id` int);\n");
        file_put_contents($dir . '/manifest.json', '{"kind":"v2board-full-backup"}');

        $archive = $dir . '.tar.gz';
        $out = [];
        $code = null;
        @exec('tar -czf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($dir) . ' . 2>&1', $out, $code);

        try {
            if ($code !== 0 || !is_file($archive)) {
                $this->markTestSkipped('tar not available here: ' . implode(' ', $out));
            }
            $this->service->assertBundleIntegrity($archive);
            $this->assertTrue(true);
        } finally {
            @unlink($archive);
            @unlink($dir . '/dump.sql');
            @unlink($dir . '/manifest.json');
            @rmdir($dir);
        }
    }

    public function testFullBackupFilenameEndsWithFullTarGz(): void
    {
        $name = $this->service->fullBackupFilename();
        $this->assertStringEndsWith('_full.tar.gz', $name);
        $this->assertStringContainsString('v2board_', $name);
    }
}
