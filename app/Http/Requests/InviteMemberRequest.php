<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InviteMemberRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:150'],
            // Checked against the workspace in the controller (roles are
            // tenant-scoped, so a plain exists rule would be unsafe).
            'role_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:invited,active,suspended'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'date_of_engagement' => ['nullable', 'date'],
        ];
    }
}
