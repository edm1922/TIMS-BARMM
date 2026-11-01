<?php

namespace App\Support;

use App\Models\SyncSetting;
use Illuminate\Support\Str;

final class SyncIdentity
{
    public static function mode(): string
    {
        return (string) config('sync.instance_mode', 'online');
    }

    public static function isOnline(): bool
    {
        return self::mode() === 'online';
    }

    public static function isOffline(): bool
    {
        return self::mode() === 'offline';
    }

    public static function centralUuid(): string
    {
        return (string) config('sync.central_server_uuid');
    }

    /**
     * The UUID that owns newly created rows on this installation.
     */
    public static function originUuid(): string
    {
        if (self::isOffline()) {
            $local = SyncSetting::get('local_server_uuid')
                ?: config('sync.local_server_uuid');

            if (is_string($local) && trim($local) !== '') {
                return $local;
            }
        }

        return self::centralUuid();
    }

    public static function protocolVersion(): int
    {
        return (int) config('sync.protocol_version', 1);
    }

    public static function minimumProtocolVersion(): int
    {
        return (int) config('sync.minimum_protocol_version', 1);
    }

    public static function schemaVersion(): int
    {
        return (int) config('sync.schema_version', 1);
    }

    public static function minimumSchemaVersion(): int
    {
        return (int) config('sync.minimum_schema_version', 1);
    }

    public static function supports(int $protocolVersion, int $schemaVersion): bool
    {
        return $protocolVersion >= self::minimumProtocolVersion()
            && $protocolVersion <= self::protocolVersion()
            && $schemaVersion >= self::minimumSchemaVersion()
            && $schemaVersion <= self::schemaVersion();
    }

    public static function newUuid(): string
    {
        return (string) Str::uuid();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array<string, int>
     */
    public static function compatibility(): array
    {
        return [
            'protocol_version' => self::protocolVersion(),
            'minimum_protocol_version' => self::minimumProtocolVersion(),
            'schema_version' => self::schemaVersion(),
            'minimum_schema_version' => self::minimumSchemaVersion(),
        ];
    }
}
