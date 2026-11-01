<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class TimsBackup extends Command
{
    protected $signature = 'tims:backup
        {--retain=7 : Number of daily backups to keep}
        {--dir= : Backup directory (default: storage/app/backups)}';

    protected $description = 'Create a timestamped database backup and prune old backups';

    public function handle(): int
    {
        $retainDays = (int) $this->option('retain');
        $backupDir  = $this->option('dir')
            ?: storage_path('app/backups');

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }

        $timestamp = now()->format('Ymd-His');
        $dumpFile  = "$backupDir/database-$timestamp.sql";

        $dbConfig = config('database.connections.' . config('database.default'));
        $host     = $dbConfig['host']     ?? '127.0.0.1';
        $port     = $dbConfig['port']     ?? 3306;
        $database = $dbConfig['database'] ?? '';
        $username = $dbConfig['backup_username'] ?? $dbConfig['username'] ?? '';
        $password = $dbConfig['backup_password'] ?? $dbConfig['password'] ?? '';

        if (! $database) {
            $this->error('Database name not configured.');
            return Command::FAILURE;
        }

        // Build mysqldump command. The web account is DML-only; backups use
        // the dedicated read-only backup account (falling back to the app
        // account only when no backup account is configured).
        $dumpBinary = is_file('C:\\xampp\\mysql\\bin\\mysqldump.exe')
            ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe'
            : 'mysqldump';

        $cmd = [
            $dumpBinary,
            "-h$host",
            "-P$port",
            "-u$username",
            "--single-transaction",
            "--routines",
            "--triggers",
            "--result-file=$dumpFile",
            $database,
        ];

        $env = ['MYSQL_PWD' => $password];

        $this->info("Dumping database '$database' → $dumpFile");

        $process = new Process($cmd, null, $env, null, 300);
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($dumpFile);
            $this->error('mysqldump failed: ' . $process->getErrorOutput());
            return Command::FAILURE;
        }

        $sizeMb = round(filesize($dumpFile) / 1_048_576, 2);
        $this->info("Backup created: $dumpFile ($sizeMb MB)");

        // Compress
        if (extension_loaded('zlib')) {
            $gzFile = $dumpFile . '.gz';
            $in     = fopen($dumpFile, 'rb');
            $out    = gzopen($gzFile, 'wb9');
            while (! feof($in)) {
                gzwrite($out, fread($in, 1_048_576));
            }
            fclose($in);
            gzclose($out);
            unlink($dumpFile);
            $this->info("Compressed: $gzFile");
        }

        // Prune old backups
        $cutoff = now()->subDays($retainDays)->getTimestamp();
        $pruned = 0;

        foreach (glob("$backupDir/database-*.sql*") as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $pruned++;
            }
        }

        if ($pruned > 0) {
            $this->line("Pruned $pruned backup(s) older than $retainDays days.");
        }

        $this->info("Backup complete.");
        return Command::SUCCESS;
    }
}
