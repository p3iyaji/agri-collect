<?php

namespace App\Traits;

use App\Models\Account;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned.
 *
 * Applies a global scope so every query automatically returns only rows
 * belonging to the workspace embedded in the current JWT. When no active
 * workspace is set (CLI, seeders, migrations) the scope is a no-op and the
 * account_id must be passed explicitly.
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope('account', function (Builder $query): void {
            $accountId = Tenancy::activeId();

            if ($accountId === null) {
                return;
            }

            $query->where($query->getModel()->qualifyColumn('account_id'), $accountId);
        });
    }

    /**
     * Escape hatch for cross-tenant admin tooling.
     */
    public function scopeWithoutAccountScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('account');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
