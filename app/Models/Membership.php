<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A row in the account_user pivot table: user <-> workspace, with the
 * role that applies within this workspace.
 */
class Membership extends Pivot
{
    protected $table = 'account_user';

    /** @var list<string> */
    protected $fillable = ['account_id', 'user_id', 'role_id', 'status'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
