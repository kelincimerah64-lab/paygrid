<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DatabaseBackupCommand extends Command
{
    protected $signature = 'paygrid:backup-db {--disk=local}';

    protected $description = 'Create a database backup artifact for production operations.';

    public function handle(): int
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $name = 'backups/paygrid-db-'.now()->format('Ymd-His').'.'.($driver === 'sqlite' ? 'sqlite' : 'sql');
        $disk = Storage::disk((string) $this->option('disk'));

        if ($driver === 'sqlite') {
            $database = (string) config("database.connections.{$connection}.database");
            if (! is_file($database)) {
                $this->error('SQLite database file not found.');

                return self::FAILURE;
            }

            $disk->put($name, file_get_contents($database));
            $this->info("Backup created: {$name}");

            return self::SUCCESS;
        }

        $this->error('Use managed RDS snapshots for production '.$driver.' backups. This command intentionally avoids embedding DB credentials in shell commands.');

        return self::FAILURE;
    }
}
