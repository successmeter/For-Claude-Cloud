<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'mfa_secret'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
            // Platform-level TOTP secret, encrypted at rest via Laravel's `encrypted`
            // cast (not the per-org EnvelopeEncryptor used for tenant data — this
            // isn't org-scoped data). Deliberately NOT added to the #[Fillable] list
            // above: mfa_secret/mfa_enabled are only ever written by MfaController via
            // forceFill(), never via mass-assignment from arbitrary request input, so a
            // future request-driven update() call elsewhere can't accidentally flip a
            // user's MFA state.
            'mfa_secret' => 'encrypted',
            'mfa_enabled' => 'boolean',
        ];
    }

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }
}
