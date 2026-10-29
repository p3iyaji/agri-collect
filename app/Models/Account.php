<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A workspace / organization (e.g. "AgriResearch NGO").
 *
 * Tenancy root: every tenant-owned row (forms, submissions, ...) carries
 * an account_id and is globally scoped to the account embedded in the JWT.
 */
#[Fillable(['name', 'slug', 'settings'])]
class Account extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Settings shape (customized in the workspace settings page):
     * {
     *   "logo_path": "workspaces/7/logo.png",  // internal, set by logo upload
     *   "organization": { name, contact_person, phone, email, address, website },
     *   "field": { project_name, region, notes }
     * }
     */

    /**
     * Public logo URL (uploaded via workspace settings), or null.
     */
    public function logoUrl(): ?string
    {
        $path = $this->settings['logo_path'] ?? null;

        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * The organization block of the settings (for public profiles).
     *
     * @return array<string, mixed>
     */
    public function organizationDetails(): array
    {
        return $this->settings['organization'] ?? [];
    }

    /**
     * The field-operations block of the settings (for public profiles).
     *
     * @return array<string, mixed>
     */
    public function fieldDetails(): array
    {
        return $this->settings['field'] ?? [];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_user')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    /**
     * Membership rows (user + role + status) for this workspace.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'account_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }
}
