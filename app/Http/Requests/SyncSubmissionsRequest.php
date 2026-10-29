<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncSubmissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Structural validation only. Per-item problems (unknown form_id, ...)
     * are reported per item in the response so one bad row never poisons
     * the whole offline batch.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'submissions' => ['required', 'array', 'min:1', 'max:100'],
            'submissions.*.local_uuid' => ['required', 'string', 'min:8', 'max:36'],
            'submissions.*.form_id' => ['required', 'integer'],
            'submissions.*.payload' => ['nullable', 'array'],
            'submissions.*.gps' => ['nullable', 'array'],
            'submissions.*.gps.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'submissions.*.gps.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'submissions.*.gps.accuracy' => ['nullable', 'numeric', 'min:0'],
            'submissions.*.gps.source' => ['nullable', 'string', 'max:16'],
            'submissions.*.created_at' => ['nullable', 'date'],
        ];
    }
}
