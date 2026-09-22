<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Panel;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Models\Contracts\HasDefaultTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;
use OwenIt\Auditing\Contracts\Auditable;

class User extends Authenticatable implements JWTSubject, FilamentUser, HasTenants, HasDefaultTenant, Auditable
{
    use HasFactory, Notifiable;
    use \OwenIt\Auditing\Auditable;
    use HasRoles {
        hasRole as traitHasRole;
        roles as traitRoles;
    }

    /**
     * Override Spatie's roles relationship to simplify team filtering logic
     */
    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        // 如果這個使用者有自己的租戶，永遠使用該租戶的ID來查詢角色
        if ($this->tenant_id) {
            $relation = $this->morphToMany(
                config('permission.models.role'),
                'model',
                config('permission.table_names.model_has_roles'),
                config('permission.column_names.model_morph_key'),
                app(\Spatie\Permission\PermissionRegistrar::class)->pivotRole
            );

            $teamsKey = app(\Spatie\Permission\PermissionRegistrar::class)->teamsKey;
            $relation->withPivot($teamsKey);

            // 只保留pivot表的過濾，roles表的過濾由Spatie內部處理，避免SQL歧義
            $relation->withPivot($teamsKey);
            return $relation->wherePivot($teamsKey, $this->tenant_id);
        }

        // 沒有租戶的使用者（超級管理員）使用Spatie的預設關係
        return $this->traitRoles();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the tenant that owns the user.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return custom claims to be added to the JWT.
     */
    public function getJWTCustomClaims()
    {
        return [
            'tenant_id' => $this->tenant_id,
        ];
    }

    /**
     * Determine if the model has (one of) the given role(s), with platform super_admin fallback.
     */
    public function hasRole($roles, ?string $guard = null): bool
    {
        if (is_null($this->tenant_id)) {
            if ($roles === 'super_admin' || (is_array($roles) && in_array('super_admin', $roles))) {
                return true;
            }
        }

        return $this->traitHasRole($roles, $guard);
    }

    /**
     * Check if the user is a super admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Determine whether the user can access the Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isSuperAdmin()) {
            \Illuminate\Support\Facades\Log::info('[Filament Auth] Super Admin panel access granted', [
                'user_id' => $this->id,
                'email' => $this->email,
            ]);
            return true;
        }

        // 🔑 若此使用者有 tenant_id，確保在進行 Panel 角色判定前，Spatie Team Context 已正確設定
        if ($this->tenant_id !== null && getPermissionsTeamId() !== (int)$this->tenant_id) {
            setPermissionsTeamId($this->tenant_id);
            $this->unsetRelation('roles');
        }

        $canAccess = $this->hasAnyRole([
            'tenant_admin',
            'tenant_staff',
        ]);

        \Illuminate\Support\Facades\Log::info('[Filament Auth] Tenant user panel access evaluation', [
            'user_id' => $this->id,
            'email' => $this->email,
            'tenant_id' => $this->tenant_id,
            'permissions_team_id' => getPermissionsTeamId(),
            'can_access' => $canAccess,
            'roles' => $this->getRoleNames(),
        ]);

        return $canAccess;
    }

    /**
     * Get the tenants that the user can access (Filament HasTenants interface requirement)
     */
    public function getTenants(Panel $panel): Collection
    {
        // 🔑 super_admin（tenant_id為null）可以存取所有租戶
        if (is_null($this->tenant_id)) {
            return Tenant::all();
        }

        // 一般使用者只能存取自己的租戶
        return collect([$this->tenant]);
    }

    /**
     * Determine whether the user can access a specific tenant (Filament Multi-tenancy)
     */
    public function canAccessTenant($tenant): bool
    {
        // 🔑 super_admin（tenant_id為null）可以存取所有租戶
        if (is_null($this->tenant_id)) {
            return true;
        }

        // 一般使用者只能存取自己的租戶
        return $this->tenant_id === $tenant->id;
    }

    /**
     * Get the default tenant for the user (Filament HasDefaultTenant interface requirement)
     */
    public function getDefaultTenant(\Filament\Panel $panel): ?\App\Models\Tenant
    {
        // 🔑 Super Admin 永遠不預設任何租戶，保持全域存取
        if ($this->isSuperAdmin()) {
            return null;
        }

        // 🔑 一般使用者使用自己的租戶作為默認
        if (!is_null($this->tenant_id)) {
            return $this->tenant;
        }

        // 非Super Admin但tenant_id為null的特殊狀況，才從session讀取
        if (session()->has('filament_tenant_id')) {
            $sessionTenant = Tenant::find(session('filament_tenant_id'));
            if ($sessionTenant) {
                return $sessionTenant;
            }
        }

        return $this->getTenants($panel)->first();
    }
}
