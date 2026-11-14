<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL autocommits. A previously interrupted update can therefore
        // leave some of these columns/indexes behind even though the migration
        // itself was never recorded. Make every step safe to re-run.
        Schema::table('beneficiaries', function (Blueprint $table): void {
            if (! Schema::hasColumn('beneficiaries', 'coverage_year')) {
                $table->unsignedSmallInteger('coverage_year')->nullable()->after('profiled_on');
            }

            if (! Schema::hasColumn('beneficiaries', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('coverage_year');
            }
        });

        Schema::table('beneficiaries', function (Blueprint $table): void {
            if (! Schema::hasIndex('beneficiaries', ['coverage_year', 'name_key'])) {
                $table->index(['coverage_year', 'name_key']);
            }

            if (! Schema::hasIndex('beneficiaries', ['coverage_year', 'source_id_no', 'origin_server_uuid'])) {
                $table->index(['coverage_year', 'source_id_no', 'origin_server_uuid']);
            }

            if (! Schema::hasIndex('beneficiaries', ['coverage_year', 'source_id_number', 'origin_server_uuid'])) {
                $table->index(['coverage_year', 'source_id_number', 'origin_server_uuid']);
            }

            if (! Schema::hasIndex('beneficiaries', ['is_active'])) {
                $table->index('is_active');
            }
        });

        if (! Schema::hasColumn('beneficiaries', 'coverage_year')) {
            return;
        }

        $batchYears = DB::table('beneficiary_batches')
            ->select(['id', 'profiled_on', 'program_year'])
            ->get()
            ->keyBy('id');

        DB::table('beneficiaries')
            ->whereNull('coverage_year')
            ->select(['id', 'beneficiary_batch_id', 'profiled_on', 'created_at'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($batchYears): void {
                foreach ($rows as $row) {
                    $batch = $row->beneficiary_batch_id !== null
                        ? $batchYears->get((int) $row->beneficiary_batch_id)
                        : null;

                    $year = null;

                    foreach ([
                        $row->profiled_on,
                        $batch?->profiled_on,
                        $batch?->program_year,
                        $row->created_at,
                    ] as $candidate) {
                        if ($candidate === null || $candidate === '') {
                            continue;
                        }

                        if (is_numeric($candidate)) {
                            $year = (int) $candidate;
                            break;
                        }

                        $timestamp = strtotime((string) $candidate);
                        if ($timestamp !== false) {
                            $year = (int) date('Y', $timestamp);
                            break;
                        }
                    }

                    DB::table('beneficiaries')->where('id', $row->id)->update([
                        'coverage_year' => $year ?? (int) now()->year,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table): void {
            if (Schema::hasIndex('beneficiaries', ['coverage_year', 'name_key'])) {
                $table->dropIndex(['coverage_year', 'name_key']);
            }
            if (Schema::hasIndex('beneficiaries', ['coverage_year', 'source_id_no', 'origin_server_uuid'])) {
                $table->dropIndex(['coverage_year', 'source_id_no', 'origin_server_uuid']);
            }
            if (Schema::hasIndex('beneficiaries', ['coverage_year', 'source_id_number', 'origin_server_uuid'])) {
                $table->dropIndex(['coverage_year', 'source_id_number', 'origin_server_uuid']);
            }
            if (Schema::hasIndex('beneficiaries', ['is_active'])) {
                $table->dropIndex(['is_active']);
            }

            if (Schema::hasColumn('beneficiaries', 'coverage_year')) {
                $table->dropColumn('coverage_year');
            }
            if (Schema::hasColumn('beneficiaries', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
