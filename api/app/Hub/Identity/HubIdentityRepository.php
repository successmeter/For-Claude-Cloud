<?php
// api/app/Hub/Identity/HubIdentityRepository.php
namespace App\Hub\Identity;

use App\Models\User;
use League\OAuth2\Server\Exception\OAuthServerException;
use OpenIDConnect\Interfaces\IdentityEntityInterface;
use OpenIDConnect\Interfaces\IdentityRepositoryInterface;

/**
 * Builds the identity behind an id_token. Passport hands over the internal users.id; the id_token's
 * `sub` must be the public UUID instead (design §4.1), so it is swapped here.
 */
class HubIdentityRepository implements IdentityRepositoryInterface
{
    public function getByIdentifier(string $identifier): IdentityEntityInterface
    {
        // No user behind the token: a client-credentials request that asked for `openid`. There
        // is nobody to issue an id_token about, so refuse the scope (400) rather than fail (500).
        $user = ctype_digit($identifier) ? User::find($identifier) : null;
        if (! $user) {
            throw OAuthServerException::invalidScope('openid');
        }

        $entity = new HubIdentityEntity($user);
        $entity->setIdentifier($user->public_id);

        return $entity;
    }
}
