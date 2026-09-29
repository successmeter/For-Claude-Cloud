<?php
// api/app/Hub/Identity/HubIdentityRepository.php
namespace App\Hub\Identity;

use App\Models\User;
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
        $user = User::findOrFail($identifier);

        $entity = new HubIdentityEntity($user);
        $entity->setIdentifier($user->public_id);

        return $entity;
    }
}
