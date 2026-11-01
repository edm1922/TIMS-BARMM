<?php

use App\Support\SyncIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Older installations could create batches/beneficiaries before a stable
     * local (or central) identity was configured. Their origin_server_uuid is
     * empty, which can collide with an incoming row of the same name under the
     * (origin_server_uuid, name) unique key. Backfill the installation's own
     * origin so those rows are correctly attributed.
     */
    public function up(): void
    {
        $origin = (string) SyncIdentity::originUuid();

        if ($origin === '') {
            return;
        }

        foreach (['beneficiaries', 'beneficiary_batches'] as $table) {
            DB::table($table)
                ->where(function ($query): void {
                    $query->whereNull('origin_server_uuid')
                        ->orWhere('origin_server_uuid', '');
                })
                ->update(['origin_server_uuid' => $origin]);
        }
    }

    public function down(): void
    {
        // Data backfill is intentionally not reversed.
    }
};
