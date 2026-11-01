<?php

namespace App\Services\Reports;

use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Explains a failed reports request instead of leaving the operator with a
 * blank 500 page.
 *
 * Offline installations can run a database that trails the application code
 * (a package copied over the site without the migration step). The reports
 * page then dies on a missing table or column. This service collects the facts
 * that identify the cause — missing schema, pending migrations, PHP and
 * database versions — writes them to the audit trail (which synchronizes to
 * the central server), and gives the page something readable to show.
 */
class ReportErrorReporter
{
    /**
     * Schema the reports page and its batch filter rely on.
     *
     * @return array<int, string>
     */
    public static function missingSchema(): array
    {
        $missing = [];

        try {
            if (! Schema::hasTable('beneficiaries')) {
                $missing[] = 'table beneficiaries';

                return $missing;
            }

            if (! Schema::hasColumn('beneficiaries', 'beneficiary_batch_id')) {
                $missing[] = 'column beneficiaries.beneficiary_batch_id';
            }

            if (! Schema::hasTable('beneficiary_batches')) {
                $missing[] = 'table beneficiary_batches';
            }
        } catch (Throwable) {
            return $missing;
        }

        return $missing;
    }

    /**
     * Migration files that this database has not run yet.
     *
     * @return array<int, string>
     */
    public static function pendingMigrations(): array
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return [];
            }

            $applied = DB::table('migrations')->pluck('migration')->all();

            $files = [];

            foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
                $files[] = basename($file, '.php');
            }

            sort($files);

            return array_values(array_diff($files, $applied));
        } catch (Throwable) {
            return [];
        }
    }

    public static function databaseVersion(): ?string
    {
        try {
            $row = DB::selectOne('select version() as version');

            return is_object($row) ? (string) $row->version : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Record the failure in the audit trail so the central server learns the
     * cause without anyone having to read the offline log file.
     */
    public static function report(string $context, ?Throwable $exception = null, array $extra = []): void
    {
        try {
            $metadata = array_merge([
                'context' => $context,
                'missing_schema' => self::missingSchema(),
                'pending_migrations' => self::pendingMigrations(),
                'php_version' => PHP_VERSION,
                'database' => self::databaseVersion(),
            ], $extra);

            if ($exception !== null) {
                $metadata['message'] = $exception->getMessage();
                $metadata['exception'] = $exception::class;
                $metadata['location'] = basename($exception->getFile()).':'.$exception->getLine();
            }

            AuditLogger::log('report.error', null, null, $metadata);
        } catch (Throwable) {
            // Diagnostics must never replace the failure they describe.
        }
    }
}
