<?php

namespace App\Models;

use App\Traits\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One completed (or partially completed) form entry.
 *
 * local_uuid is generated on the device before the first sync attempt and
 * is unique per workspace — it is the idempotency key of the offline
 * sync engine (see SyncController).
 */
#[Fillable([
    'account_id',
    'form_id',
    'local_uuid',
    'payload',
    'gps_lat',
    'gps_lng',
    'gps_accuracy',
    'gps_source',
    'submitted_by',
    'synced_at',
    'created_at',
])]
class Submission extends Model
{
    use BelongsToAccount;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'gps_lat' => 'float',
            'gps_lng' => 'float',
            'gps_accuracy' => 'float',
            'synced_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SubmissionAttachment::class);
    }
}
