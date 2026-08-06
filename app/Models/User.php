<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * An LGU employee.
 *
 * Accounts are always created by an administrator — there is no public
 * registration. Google sign-in, when configured, only links to an account that
 * already exists.
 *
 * Users are deactivated rather than deleted: they are referenced by the
 * append-only routing and audit trails, so the rows must survive an employee
 * leaving. Only their access is revoked.
 */
#[Fillable([
    'employee_no', 'name', 'email', 'password', 'department_id',
    'position', 'google_id', 'is_active',
])]
#[Hidden(['password', 'remember_token', 'google_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Whether this account may sign in at all. Checked on every authentication
     * attempt and on every request via EnsureUserIsActive, so deactivating an
     * employee takes effect immediately rather than at session expiry.
     */
    public function canSignIn(): bool
    {
        return $this->is_active;
    }

    /** Name with position appended, for routing slips and document trails. */
    public function displayName(): string
    {
        return $this->position
            ? "{$this->name} ({$this->position})"
            : $this->name;
    }

    public function isHeadOfDepartment(): bool
    {
        return $this->department !== null
            && $this->department->head_user_id === $this->id;
    }
}
