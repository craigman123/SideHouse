<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $primaryKey = 'user_id';

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
        'phone_number',
        'mfa_enabled',
        'mfa_secret',
        'mfa_recovery_codes',
        'mfa_method',
        'permissions',
        'access_version',
        'promoted_by',
        'promoted_at',
        'last_login_at',
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
     * Merged into a single casts() method — having both a $casts property
     * and a casts() method causes the method to silently win in Laravel 10+,
     * dropping mfa_enabled, mfa_recovery_codes, and mfa_secret from casting.
     *
     * mfa_secret uses the 'encrypted' cast so the model handles
     * encryption/decryption automatically — no manual encrypt()/decrypt()
     * needed in controllers.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'password'           => 'hashed',
            'mfa_enabled'        => 'boolean',
            'mfa_secret'         => 'encrypted',
            'mfa_recovery_codes' => 'array',
            'permissions'        => 'array',
            'access_version'     => 'integer',
            'promoted_at'        => 'datetime',
            'last_login_at'      => 'datetime',
        ];
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_enabled && !empty($this->mfa_secret);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function requiresMfa(): bool
    {
        return in_array($this->role, ['owner', 'admin', 'staff'], true);
    }

    public function promotedBy()
    {
        return $this->belongsTo(self::class, 'promoted_by', 'user_id');
    }
}
