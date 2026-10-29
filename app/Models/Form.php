<?php

namespace App\Models;

use App\Traits\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A data-collection form. The full field definition (types, options,
 * skip logic) is stored as a JSON schema so clients can render it
 * dynamically and cache it for offline use.
 *
 * Schema shape:
 * {
 *   "fields": [
 *     { "name", "label", "type": text|number|select|date|gps|photo,
 *       "required"?, "help_text"?, "options"?, "visible_if"? }
 *   ]
 * }
 */
#[Fillable(['account_id', 'name', 'slug', 'description', 'schema', 'version', 'status'])]
class Form extends Model
{
    use BelongsToAccount;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'version' => 'integer',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
