<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Default attribute values (applied to new instances before insert).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => self::ROLE_STAFF,
        'is_active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'is_active',
        'uuid',
    ];

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_ENCODER = 'encoder';

    public const ROLE_STAFF = 'staff';

    public const ROLE_LABELS = [
        self::ROLE_ADMIN => 'Administrator',
        self::ROLE_MANAGER => 'Manager',
        self::ROLE_ENCODER => 'Encoder',
        self::ROLE_STAFF => 'Staff',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Give every account a stable identifier so audit records stay
     * attributable after the account is renamed, deactivated, or synced.
     */
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if ($user->uuid === null || $user->uuid === '') {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * The minimal identity carried in audit records and in the encoder
     * registry that synchronizes to the central server. It never contains
     * credentials or personal contact details.
     *
     * @return array{uuid: string, username: string, name: string, role: string, is_active: bool}
     */
    public function auditIdentity(): array
    {
        return [
            'uuid' => (string) $this->uuid,
            'username' => (string) $this->username,
            'name' => (string) $this->name,
            'role' => (string) $this->role,
            'is_active' => (bool) $this->is_active,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isEncoder(): bool
    {
        return $this->role === self::ROLE_ENCODER;
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function canManageBatches(): bool
    {
        return $this->isAdmin() || $this->isManager();
    }

    public function canAccessImports(): bool
    {
        return $this->isAdmin() || $this->isStaff();
    }

    public function canAccessReports(): bool
    {
        return $this->isAdmin() || $this->isStaff() || $this->isManager();
    }

    public function canAccessAudit(): bool
    {
        return $this->isAdmin() || $this->isManager();
    }
}
