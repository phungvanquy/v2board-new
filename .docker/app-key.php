<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

$mode = $argv[1] ?? 'check';
$path = $argv[2] ?? dirname(__DIR__) . '/.env';

$validate = static function ($key): void {
    if (!is_string($key) || $key === '') {
        throw new RuntimeException('APP_KEY is empty.');
    }

    $decoded = strncmp($key, 'base64:', 7) === 0 ? base64_decode(substr($key, 7), true) : $key;
    if ($decoded === false || !Encrypter::supported($decoded, 'AES-256-CBC')) {
        throw new RuntimeException('APP_KEY is invalid for AES-256-CBC; preserve or restore the existing key instead of replacing it.');
    }
};

try {
    if ($mode === 'check') {
        // Use Laravel's exact precedence, without booting Laravel or consulting
        // a potentially stale config cache. An exported empty value must fail.
        Dotenv::create(Env::getRepository(), dirname($path), basename($path))->safeLoad();
        $validate(Env::get('APP_KEY'));
        exit(0);
    }

    if ($mode !== 'prepare') {
        throw new RuntimeException('Usage: app-key.php [prepare|check] [env-file]');
    }

    $file = @fopen($path, 'r+');
    if ($file === false || !flock($file, LOCK_EX)) {
        throw new RuntimeException('Cannot open and lock .env for setup. Check that the file exists and is writable.');
    }

    $contents = stream_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException('Cannot read .env.');
    }

    $values = Dotenv::parse($contents);
    $pattern = '/^[\t ]*(?:export[\t ]+)?APP_KEY[\t ]*=[^\r\n]*/m';
    $count = preg_match_all($pattern, $contents);
    if ($count > 1 || (array_key_exists('APP_KEY', $values) && $count !== 1)) {
        throw new RuntimeException('Use a single unquoted APP_KEY variable name in .env.');
    }

    $key = $values['APP_KEY'] ?? '';
    if ($key !== '') {
        $validate($key);
        fwrite(STDOUT, "setup: existing APP_KEY preserved\n");
        exit(0);
    }

    // Do not silently rotate a key supplied by an environment/secret override.
    if (getenv('APP_KEY') !== false && getenv('APP_KEY') !== '') {
        throw new RuntimeException('APP_KEY is set in the container environment but missing from .env. Persist the existing key in .env before setup.');
    }

    $key = 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC'));
    $line = 'APP_KEY=' . $key;
    if ($count === 1) {
        $updated = preg_replace_callback($pattern, static function () use ($line): string {
            return $line;
        }, $contents, 1);
    } else {
        $newline = strpos($contents, "\r\n") !== false ? "\r\n" : "\n";
        $updated = $contents . ($contents !== '' && substr($contents, -1) !== "\n" ? $newline : '') . $line . $newline;
    }

    if ((Dotenv::parse($updated)['APP_KEY'] ?? null) !== $key) {
        throw new RuntimeException('Cannot safely update APP_KEY in .env. Use a single APP_KEY= line.');
    }

    // Write through the existing inode: renaming a bind-mounted .env would
    // leave existing containers pointing to the old file. The lock also makes
    // concurrent setup commands preserve the first generated key.
    if (!rewind($file) || fwrite($file, $updated) !== strlen($updated) || !ftruncate($file, strlen($updated)) || !fflush($file)) {
        throw new RuntimeException('Cannot save APP_KEY to .env. Check disk space and file permissions.');
    }

    fwrite(STDOUT, "setup: generated a unique APP_KEY in .env\n");
} catch (\Dotenv\Exception\InvalidFileException $e) {
    // Parser exceptions can include .env values; never print them to logs.
    fwrite(STDERR, "[app-key] Invalid .env syntax. Correct the file before starting the stack.\n");
    exit(1);
} catch (RuntimeException $e) {
    fwrite(STDERR, '[app-key] ' . $e->getMessage() . "\n");
    if ($mode === 'check') {
        fwrite(STDERR, "[app-key] Run 'sh docker-setup.sh' on the host, then 'docker compose up -d --force-recreate' to reload .env.\n");
    }
    exit(1);
}
