<?php

namespace App\Http\Resources;

use App\Models\Form;

/**
 * JSON:API style serialization for forms.
 */
class FormResource
{
    /**
     * @return array<string, mixed>
     */
    public static function item(Form $form): array
    {
        return [
            'type' => 'forms',
            'id' => (string) $form->getKey(),
            'attributes' => self::attributes($form),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributes(Form $form): array
    {
        return [
            'name' => $form->name,
            'slug' => $form->slug,
            'description' => $form->description,
            'schema' => $form->schema,
            'version' => (int) $form->version,
            'status' => $form->status,
            'created_at' => $form->created_at?->toIso8601String(),
            'updated_at' => $form->updated_at?->toIso8601String(),
        ];
    }
}
