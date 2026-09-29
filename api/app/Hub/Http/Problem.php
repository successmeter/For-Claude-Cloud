<?php
// api/app/Hub/Http/Problem.php
namespace App\Hub\Http;

use Illuminate\Http\JsonResponse;

/**
 * RFC 9457 problem details, the error format of hub contract v1 (design §4.5).
 */
class Problem
{
    public static function response(int $status, string $type, string $title, array $extra = []): JsonResponse
    {
        return new JsonResponse(
            ['type' => 'https://hub/problems/'.$type, 'title' => $title, 'status' => $status] + $extra,
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
