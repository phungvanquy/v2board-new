<?php

declare(strict_types=1);

namespace Tests\Unit\Docker;

use Dotenv\Dotenv;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AppKeyTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        $this->envPath = tempnam(sys_get_temp_dir(), 'v2board-app-key-');
    }

    protected function tearDown(): void
    {
        unlink($this->envPath);
    }

    private function command(string $mode, array $environment = []): Process
    {
        return new Process(
            [PHP_BINARY, dirname(__DIR__, 3) . '/.docker/app-key.php', $mode, $this->envPath],
            null,
            array_merge(['APP_KEY' => false], $environment)
        );
    }

    /** @dataProvider emptyKeyFiles */
    public function testSetupGeneratesAUsableKeyAndPreservesOtherSettings(string $contents): void
    {
        file_put_contents($this->envPath, $contents);
        $process = $this->command('prepare', ['APP_KEY' => '']);
        $process->mustRun();

        $updated = file_get_contents($this->envPath);
        $values = Dotenv::parse($updated);
        $key = $values['APP_KEY'];
        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame('keep-this-value', $values['UNRELATED']);
        $encrypter = new Encrypter(base64_decode(substr($key, 7), true), 'AES-256-CBC');
        $this->assertSame('round trip', $encrypter->decryptString($encrypter->encryptString('round trip')));
        $this->assertStringNotContainsString($key, $process->getOutput() . $process->getErrorOutput());

        // A second setup must not rotate the key or rewrite the file.
        $this->command('prepare')->mustRun();
        $this->assertSame($updated, file_get_contents($this->envPath));
    }

    public static function emptyKeyFiles(): array
    {
        return [
            'empty' => ["APP_KEY=\nUNRELATED=keep-this-value\n"],
            'quoted empty' => ["APP_KEY=\"\"\nUNRELATED=keep-this-value\n"],
            'single quoted empty and CRLF' => ["APP_KEY=''\r\nUNRELATED=keep-this-value\r\n"],
            'whitespace and comment' => ["  APP_KEY = # create a key\nUNRELATED=keep-this-value\n"],
            'missing, no final newline' => ['UNRELATED=keep-this-value'],
        ];
    }

    public function testSeparateInstallationsGetDifferentKeys(): void
    {
        file_put_contents($this->envPath, "APP_KEY=\n");
        $this->command('prepare')->mustRun();
        $first = Dotenv::parse(file_get_contents($this->envPath))['APP_KEY'];
        file_put_contents($this->envPath, "APP_KEY=\n");
        $this->command('prepare')->mustRun();
        $second = Dotenv::parse(file_get_contents($this->envPath))['APP_KEY'];
        $this->assertNotSame($first, $second);
    }

    public function testExistingQuotedKeyAndFileFormattingArePreserved(): void
    {
        $key = 'base64:' . base64_encode(random_bytes(32));
        $contents = "# Keep formatting\r\nAPP_KEY=\"{$key}\" # existing\r\nUNRELATED=kept\r\n";
        file_put_contents($this->envPath, $contents);
        $this->command('prepare')->mustRun();
        $this->assertSame($contents, file_get_contents($this->envPath));
        $this->command('check', ['APP_KEY' => $key])->mustRun();
    }

    /** @dataProvider invalidKeyFiles */
    public function testSetupRejectsAmbiguousOrInvalidKeysWithoutChangingTheFile(string $contents): void
    {
        file_put_contents($this->envPath, $contents);
        $process = $this->command('prepare');
        $this->assertSame(1, $process->run());
        $this->assertSame($contents, file_get_contents($this->envPath));
    }

    public static function invalidKeyFiles(): array
    {
        return [
            'placeholder' => ["APP_KEY=EXAMPLE_KEY\n"],
            'wrong size' => ['APP_KEY=base64:' . base64_encode('too short') . "\n"],
            'malformed base64' => ["APP_KEY=base64:!!!\n"],
            'duplicate' => ["APP_KEY=\nAPP_KEY=\n"],
        ];
    }

    public function testEmptyDockerEnvironmentCannotOverrideAGeneratedKeySilently(): void
    {
        file_put_contents($this->envPath, "APP_KEY=\n");
        $this->command('prepare', ['APP_KEY' => ''])->mustRun();
        $contents = file_get_contents($this->envPath);
        $key = Dotenv::parse($contents)['APP_KEY'];

        // This is the original failure: .env is populated but Docker still
        // exports the empty value captured before key generation.
        $staleContainer = $this->command('check', ['APP_KEY' => '']);
        $this->assertSame(1, $staleContainer->run());
        $this->assertStringContainsString('--force-recreate', $staleContainer->getErrorOutput());

        // Recreating the container captures the generated key. File-only
        // Laravel environments also work when APP_KEY is not exported.
        $this->command('check', ['APP_KEY' => $key])->mustRun();
        $this->command('check')->mustRun();
        $this->assertSame($contents, file_get_contents($this->envPath));
    }

    public function testSetupDoesNotRotateAnExternallyConfiguredKey(): void
    {
        $contents = "APP_KEY=\n";
        file_put_contents($this->envPath, $contents);
        $process = $this->command('prepare', ['APP_KEY' => 'base64:' . base64_encode(random_bytes(32))]);
        $this->assertSame(1, $process->run());
        $this->assertSame($contents, file_get_contents($this->envPath));
    }

    public function testConcurrentSetupPreservesOneKey(): void
    {
        file_put_contents($this->envPath, "APP_KEY=\n");
        $first = $this->command('prepare');
        $second = $this->command('prepare');
        $first->start();
        $second->start();
        $this->assertSame(0, $first->wait());
        $this->assertSame(0, $second->wait());
        $this->assertSame(1, substr_count($first->getOutput() . $second->getOutput(), 'generated a unique APP_KEY'));
        $this->command('check')->mustRun();
    }

    public function testInvalidEnvSyntaxDoesNotExposeSecrets(): void
    {
        $secret = 'private secret with spaces';
        $contents = "APP_KEY=\nPASSWORD={$secret}\n";
        file_put_contents($this->envPath, $contents);
        $process = $this->command('prepare');
        $this->assertSame(1, $process->run());
        $this->assertStringNotContainsString($secret, $process->getErrorOutput() . $process->getOutput());
        $this->assertSame($contents, file_get_contents($this->envPath));
    }
}
