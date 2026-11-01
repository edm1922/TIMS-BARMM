<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_servers', function (Blueprint $table): void {
            if (! Schema::hasColumn('offline_servers', 'authorized_at')) {
                $table->timestamp('authorized_at')->nullable()->after('enrolled_at');
            }

            if (! Schema::hasColumn('offline_servers', 'authorization_expires_at')) {
                $table->timestamp('authorization_expires_at')->nullable()->after('authorized_at');
            }
        });

        if (! Schema::hasIndex('offline_servers', ['authorization_expires_at'])) {
            Schema::table('offline_servers', function (Blueprint $table): void {
                $table->index('authorization_expires_at');
            });
        }

        Schema::table('offline_server_enrollment_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('offline_server_enrollment_tokens', 'token_type')) {
                $table->string('token_type', 20)->default('enrollment')->after('id');
            }

            if (! Schema::hasColumn('offline_server_enrollment_tokens', 'device_label')) {
                $table->string('device_label', 120)->nullable()->after('token_type');
            }

            if (! Schema::hasColumn('offline_server_enrollment_tokens', 'authorization_expires_at')) {
                $table->timestamp('authorization_expires_at')->nullable()->after('expires_at');
            }

            if (! Schema::hasColumn('offline_server_enrollment_tokens', 'target_offline_server_id')) {
                $table->foreignId('target_offline_server_id')
                    ->nullable()
                    ->after('used_by_server_id');
                $table->foreign('target_offline_server_id', 'offline_enrollment_token_server_fk')
                    ->references('id')
                    ->on('offline_servers')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('offline_server_enrollment_tokens', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign('offline_enrollment_token_server_fk');
            }

            $table->dropColumn([
                'token_type',
                'device_label',
                'authorization_expires_at',
                'target_offline_server_id',
            ]);
        });

        Schema::table('offline_servers', function (Blueprint $table): void {
            $table->dropIndex(['authorization_expires_at']);
            $table->dropColumn([
                'authorized_at',
                'authorization_expires_at',
            ]);
        });
    }
};
