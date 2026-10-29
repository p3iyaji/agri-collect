<?php

namespace App\Models;

use App\Traits\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Server-side receipt for a device-generated local_uuid.
 *
 * Guarantees exactly-once semantics for the offline sync: if the server
 * processed a submission but the response was lost in transit, the
 * retried batch finds the receipt (and the unique submissions index) and
 * returns the existing row instead of creating a duplicate.
 */
#[Fillable(['account_id', 'local_uuid', 'status', 'response', 'attempts'])]
class SyncReceipt extends Model
{
    use BelongsToAccount;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response' => 'array',
            'attempts' => 'integer',
        ];
    }

    public function submission(): HasOne
    {
        return $this->hasOne(Submission::class, 'local_uuid', 'local_uuid');
    }
}
