<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class SyncSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    /** @var array<string, string|null> */
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        try {
            $value = static::query()->where('key', $key)->value('value');
        } catch (Throwable) {
            return $default;
        }

        self::$cache[$key] = $value;

        return $value ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        self::$cache[$key] = $value;
    }

    public static function forget(string $key): void
    {
        static::query()->where('key', $key)->delete();

        unset(self::$cache[$key]);
    }

    public static function getEncrypted(string $key, ?string $default = null): ?string
    {
        $value = static::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $default;
        }
    }

    public static function setEncrypted(string $key, ?string $value): void
    {
        static::set($key, $value === null ? null : Crypt::encryptString($value));
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
