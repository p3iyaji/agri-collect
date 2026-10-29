<?php

namespace App\Models;

use App\Traits\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo / document uploaded against a synced submission.
 */
#[Fillable(['account_id', 'submission_id', 'field_name', 'mime_type', 'bytes', 'path'])]
class SubmissionAttachment extends Model
{
    use BelongsToAccount;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
