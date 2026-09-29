<?php
// api/app/Hub/Http/Middleware/AuthenticateHubCaller.php
namespace App\Hub\Http\Middleware;

use App\Hub\Http\HubCaller;
use App\Hub\Identity\FirstPartyClient;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Contracts\ScopeAuthorizable;
use Laravel\Passport\Exceptions\MissingScopeException;
use Laravel\Passport\Http\Middleware\ValidateToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * `auth.hub:<scope>,...` -- authenticates a /hub/v1 request by bearer token, user or
 * client-credentials alike (Passport's own guard only knows user tokens), requires the listed
 * scopes, and exposes the caller as HubCaller. A user token also becomes the request's user, so
 * policies, `tenant` and the audit log see them.
 */
class AuthenticateHubCaller extends ValidateToken
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $token = $this->validateToken($request);

        // For a client-credentials token, league/oauth2-server puts the client's id in the token's
        // subject, so "no user" shows up as subject == client id, not as an empty subject.
        $clientId = (string) $token->oauth_client_id;
        $userId = (string) ($token->oauth_user_id ?? '');
        $user = ($userId !== '' && $userId !== $clientId) ? User::find($userId) : null;
        $client = FirstPartyClient::find($clientId);

        $caller = new HubCaller($user, $clientId, $client?->hub_tool, $token->oauth_scopes ?? []);

        foreach ($scopes as $scope) {
            if (! $caller->can($scope)) {
                throw new MissingScopeException($scope);
            }
        }

        if ($user) {
            Auth::setUser($user);
        }
        $request->attributes->set('hub.caller', $caller);

        return $next($request);
    }

    /** Scopes are checked against HubCaller in handle(), which knows the write-needs-a-user rule. */
    protected function validate(ScopeAuthorizable $token, string ...$params): void {}
}
