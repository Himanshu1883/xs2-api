<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const ADMIN_USER_TYPE = 6;

    public const ACTIVE_STATUS = 1;

    protected $fillable = ['first_name', 'last_name', 'email', 'password', 'status', 'user_type', 'store_id', 'two_factor_enabled'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_otp'];

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
            'status' => 'integer',
            'store_id' => 'integer',
            'two_factor_enabled' => 'boolean',
            'user_type' => 'integer',
        ];
    }

    /**
     * Seatsbroker reseller administrators use user type 6
     */
    public function isAdmin(): bool
    {
        return $this->user_type === self::ADMIN_USER_TYPE;
    }

    /**
     * Determine whether this shared identity may use the provider console.
     */
    public function canAccessProviderConsole(): bool
    {
        return $this->isAdmin()
            && $this->status === self::ACTIVE_STATUS
            && $this->store_id === (int) config('provider-auth.store_id')
            && ! $this->two_factor_enabled
            && $this->deleted_at === null;
    }

    public function getDisplayNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->getAttribute('first_name'),
            $this->getAttribute('last_name'),
        ])));
    }
}
