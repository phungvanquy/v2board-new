<?php
// Seeded by entrypoint when ADMIN_EMAIL / ADMIN_PASSWORD are set and DB was just imported.
// Boots Laravel without requiring an interactive console.

$adminEmail = getenv('ADMIN_EMAIL') ?: '';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';

if ($adminEmail === '' || $adminPassword === '') {
    fwrite(STDERR, "ADMIN_EMAIL or ADMIN_PASSWORD is empty — skipping.\n");
    exit(0);
}
if (strlen($adminPassword) < 8) {
    fwrite(STDERR, "ADMIN_PASSWORD must be at least 8 characters.\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

// Bail if users table already has rows (idempotent)
try {
    if (User::query()->exists()) {
        // If an admin with this email already exists, do nothing
        if (User::where('email', $adminEmail)->exists()) {
            fwrite(STDOUT, "Admin $adminEmail already exists — skipping.\n");
            exit(0);
        }
        // Users exist but this email is new — create as admin anyway
    }
} catch (Throwable $e) {
    fwrite(STDERR, "DB probe failed: " . $e->getMessage() . "\n");
    exit(1);
}

$user = new User();
$user->email = $adminEmail;
$user->password = password_hash($adminPassword, PASSWORD_DEFAULT);
$user->uuid = Helper::guid(true);
$user->token = Helper::guid();
$user->is_admin = 1;

if ($user->save()) {
    fwrite(STDOUT, "Admin $adminEmail created.\n");
    exit(0);
}

fwrite(STDERR, "Failed to create admin $adminEmail.\n");
exit(1);
