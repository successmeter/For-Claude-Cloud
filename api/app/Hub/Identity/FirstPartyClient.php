<?php
// api/app/Hub/Identity/FirstPartyClient.php
namespace App\Hub\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;

/**
 * OAuth client model. Every client in Plan B is one of our own tools (the Web Performance tool
 * now, the Execution tool later), so first-party clients skip the consent screen. There is no
 * consent screen for anything else: third-party clients are refused (see AppServiceProvider).
 */
class FirstPartyClient extends Client
{
    protected function casts(): array
    {
        return parent::casts() + ['post_logout_redirect_uris' => 'array'];
    }

    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return $this->firstParty();
    }
}
