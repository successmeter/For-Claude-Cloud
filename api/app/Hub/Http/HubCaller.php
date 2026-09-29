<?php
// api/app/Hub/Http/HubCaller.php
namespace App\Hub\Http;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who is calling /hub/v1, from the bearer token: a user (authorization-code token) acting through a
 * client, or a tool on its own (client-credentials token, no user). Set on the request by
 * AuthenticateHubCaller.
 */
final class HubCaller
{
    public function __construct(
        public readonly ?User $user,
        public readonly string $clientId,
        public readonly ?string $tool,
        public readonly array $scopes,
    ) {}

    public static function of(Request $request): ?self
    {
        return $request->attributes->get('hub.caller');
    }

    public function isToolOnly(): bool
    {
        return $this->user === null;
    }

    /**
     * Writes always need a user (design §4.5): a tool's own token is refused
     * competitor-sets:write even if the scope was granted.
     */
    public function can(string $scope): bool
    {
        if ($scope === 'competitor-sets:write' && $this->isToolOnly()) {
            return false;
        }

        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }
}
