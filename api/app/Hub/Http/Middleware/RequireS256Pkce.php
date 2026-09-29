<?php
// api/app/Hub/Http/Middleware/RequireS256Pkce.php
namespace App\Hub\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * PKCE is mandatory for every client, and only with S256. The OAuth server itself would also
 * accept `plain`, or no challenge at all for a confidential client (Plan B spike finding 5).
 * Placed on Passport's authorize route in AppServiceProvider.
 */
class RequireS256Pkce
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->query('code_challenge') || $request->query('code_challenge_method') !== 'S256') {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'PKCE with code_challenge_method=S256 is required.',
            ], 400);
        }

        return $next($request);
    }
}
