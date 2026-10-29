<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveRoleRequest extends FormRequest
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
        $allowed = implode(',', array_keys(config('tenancy.permission_catalog')));

        return [
            'name' => ['required', 'string', 'max:50'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string', 'in:'.$allowed],
        ];
    }
}
