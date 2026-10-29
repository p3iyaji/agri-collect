<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:draft,active,archived'],
            'schema' => ['required', 'array'],
            'schema.fields' => ['required', 'array', 'min:1'],
            'schema.fields.*.name' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'schema.fields.*.label' => ['required', 'string', 'max:150'],
            'schema.fields.*.type' => ['required', 'in:text,number,select,date,gps,photo'],
            'schema.fields.*.required' => ['nullable', 'boolean'],
            'schema.fields.*.help_text' => ['nullable', 'string', 'max:300'],
            'schema.fields.*.options' => ['nullable', 'array'],
            'schema.fields.*.options.*' => ['string', 'max:100'],
            // Skip logic (validated structurally in FormController).
            'schema.fields.*.visible_if' => ['nullable', 'array'],
            'schema.fields.*.visible_if.field' => ['nullable', 'string', 'max:100'],
            'schema.fields.*.visible_if.op' => ['nullable', 'in:eq,neq,gt,lt,set'],
            'schema.fields.*.visible_if.value' => ['nullable', 'string', 'max:100'],
        ];
    }
}
