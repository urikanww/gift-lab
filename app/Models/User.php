<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property int|null $company_id
 * @property string $email
 * @property UserRole $role
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'password',
        'role',
        'permissions',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'permissions' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, User>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<SavedAddress>
     */
    public function savedAddresses(): HasMany
    {
        return $this->hasMany(SavedAddress::class);
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    public function isSuperadmin(): bool
    {
        return $this->role === UserRole::Superadmin;
    }

    /**
     * The access this user actually has, resolved from role + granted set.
     *
     *  - superadmin: everything, always. Never restricted.
     *  - staff_admin: the explicit `permissions` allowlist; NULL means
     *    unrestricted (grandfathered), so it resolves to everything too.
     *  - buyer: none - these permissions govern the staff console only.
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        if ($this->isSuperadmin()) {
            return Permissions::all();
        }

        if ($this->role !== UserRole::StaffAdmin) {
            return [];
        }

        // NULL = never restricted; an array (even empty) is an explicit grant.
        return $this->permissions === null ? Permissions::all() : array_values($this->permissions);
    }

    /**
     * Whether this user may perform a "section.action". Superadmin is always
     * true; a staff_admin with no explicit set is grandfathered to true; anyone
     * else is checked against their granted list.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        if ($this->role !== UserRole::StaffAdmin) {
            return false;
        }

        return $this->permissions === null || in_array($permission, $this->permissions, true);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
