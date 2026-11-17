<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Auth\DemoAccount;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A paying customer of the public app: own table and `customer` guard,
 * separate from admin users (D-012). Never hard-deleted.
 *
 * No $fillable: every write goes through an action with forceFill() (house rule).
 * Authenticates on the `customer` guard with the `customers` password broker;
 * roles and permissions live on that guard only (spec 3-01). No
 * MustVerifyEmail: registration has no email verification (D-030).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property bool $is_demo
 * @property CarbonImmutable|null $demo_expires_at
 * @property int $balance_nanos
 * @property int $held_nanos
 * @property CarbonImmutable|null $last_active_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Hidden(['password', 'remember_token'])]
final class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** Spatie roles and permissions resolve on the customer guard, never `web`. */
    protected string $guard_name = 'customer';

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_demo' => false,
        'balance_nanos' => 0,
        'held_nanos' => 0,
    ];

    /**
     * D-024: balance is credits owned (Σ ledger), held is Σ open usage holds.
     * Available may be negative after an over-estimate charge (spec 3-03 §6).
     */
    public function available(): Nanodollars
    {
        return $this->balance()->minus($this->held());
    }

    public function balance(): Nanodollars
    {
        return Nanodollars::fromInt($this->balance_nanos);
    }

    public function held(): Nanodollars
    {
        return Nanodollars::fromInt($this->held_nanos);
    }

    /**
     * One of the seeded, publicly shared demo logins (P02 panel, README).
     * Its name, email and password are locked (D-072).
     */
    public function isSharedDemoAccount(): bool
    {
        return in_array($this->email, DemoAccount::emails(), true);
    }

    /** D-065: an ephemeral demo customer past `demo_expires_at` is logged out lazily. */
    public function isExpiredDemo(): bool
    {
        return $this->is_demo
            && $this->demo_expires_at !== null
            && ! $this->demo_expires_at->isFuture();
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Take, $this> */
    public function takes(): HasMany
    {
        return $this->hasMany(Take::class);
    }

    /** @return HasMany<Suggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(Suggestion::class);
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'is_demo' => 'boolean',
            'demo_expires_at' => 'immutable_datetime',
            'balance_nanos' => 'integer',
            'held_nanos' => 'integer',
            'last_active_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
