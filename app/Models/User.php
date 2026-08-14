<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'avatar',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function roles()
    {
        return $this->hasMany(UserRole::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->relationLoaded('roles')
            ? $this->roles->contains('role', $role)
            : $this->roles()->where('role', $role)->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        if ($roles === []) {
            return false;
        }

        return $this->relationLoaded('roles')
            ? $this->roles->contains(fn (UserRole $userRole) => in_array($userRole->role, $roles, true))
            : $this->roles()->whereIn('role', $roles)->exists();
    }

    public function roleNames(): array
    {
        return $this->roles()->orderBy('id')->pluck('role')->all();
    }

    public function addRole(string $role): UserRole
    {
        if (! in_array($role, UserRole::ALLOWED, true)) {
            throw new \InvalidArgumentException("Unsupported role [{$role}].");
        }

        return $this->roles()->firstOrCreate(['role' => $role]);
    }

    public function driver()
    {
        return $this->hasOne(Driver::class);
    }

    public function merchant()
    {
        return $this->hasOne(Merchant::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
}
