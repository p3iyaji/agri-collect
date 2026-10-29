<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;

/**
 * Consistent JSON:API-style serialization for all API endpoints.
 *
 * Single documents:  { "data": { "type", "id", "attributes", "relationships" } }
 * Collections:       { "data": [ ... ], "meta": { "total", "page", "per_page" } }
 */
trait JsonApiResponse
{
    /**
     * Build a single JSON:API style resource document.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $relationships
     */
    protected function resource(string $type, string $id, array $attributes, array $relationships = []): JsonResponse
    {
        $data = [
            'type' => $type,
            'id' => $id,
            'attributes' => $attributes,
        ];

        if ($relationships !== []) {
            $data['relationships'] = $relationships;
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Build a JSON:API style collection document.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $additional
     */
    protected function collection(array $items, ?int $total = null, ?int $page = null, ?int $perPage = null, array $additional = []): JsonResponse
    {
        $meta = null;

        if ($total !== null) {
            $meta = [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                ...$additional,
            ];
        }

        return response()->json(array_filter([
            'data' => $items,
            'meta' => $meta,
        ]));
    }
}
