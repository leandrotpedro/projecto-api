<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 */
#[Fillable(['name', 'email', 'password', 'phone', 'address', 'bi'])]
#[Hidden([
    'password',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    protected $attributes = [
        'role' => 'cliente',
    ];

    use HasApiTokens,
        Notifiable,
        PasskeyAuthenticatable,
        SoftDeletes,
        TwoFactorAuthenticatable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if ($user->isClient()) {
                $user->syncClientProfile();
            }
        });

        static::updated(function (User $user): void {
            if ($user->isClient() && ($user->wasChanged('role') || $user->wasChanged(['name', 'phone']))) {
                $user->syncClientProfile();
            } elseif ($user->wasChanged('role')) {
                $user->client()->delete();
            }
        });
    }

    protected function syncClientProfile(): void
    {
        $client = $this->client()->first();
        if ($client === null && is_string($this->phone) && trim($this->phone) !== '') {
            $matchingClients = Client::query()
                ->whereNull('user_id')
                ->where('phone', trim($this->phone))
                ->orderBy('id')
                ->limit(2)
                ->get();

            if ($matchingClients->count() === 1) {
                $client = $matchingClients->first();
            }
        }

        if ($client !== null) {
            $client->update([
                'user_id' => $this->id,
                'name' => $this->name,
                'phone' => $this->phone,
            ]);

            return;
        }

        $this->client()->updateOrCreate([], [
            'name' => $this->name,
            'phone' => $this->phone,
        ]);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function recordedSales(): HasMany
    {
        return $this->hasMany(Sale::class, 'created_by');
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function attributedSales(): HasMany
    {
        return $this->hasMany(Sale::class, 'seller_id');
    }

    /**
     * @return HasOne<Client, $this>
     */
    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    /**
     * @return HasMany<CashSession, $this>
     */
    public function cashSessions(): HasMany
    {
        return $this->hasMany(CashSession::class, 'opened_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'administrador';
    }

    public function isManager(): bool
    {
        return $this->role === 'gerente';
    }

    public function isSeller(): bool
    {
        return $this->role === 'vendedor';
    }

    public function isClient(): bool
    {
        return $this->role === 'cliente';
    }

    public function isCashier(): bool
    {
        return $this->role === 'caixa';
    }

    public function canViewAllSales(): bool
    {
        return $this->isAdmin() || $this->isManager();
    }

    public function canManageSales(): bool
    {
        return $this->isAdmin() || $this->isManager() || $this->isSeller() || $this->isCashier();
    }

    public function currentAccessToken(): PersonalAccessToken|TransientToken|null
    {
        return $this->accessToken;
    }
}
