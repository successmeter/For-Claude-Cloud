<?php
// api/app/Hub/Identity/HubIdentityEntity.php
namespace App\Hub\Identity;

use App\Models\User;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use OpenIDConnect\Claims\Traits\WithClaims;
use OpenIDConnect\Entities\Traits\WithCustomPermittedFor;
use OpenIDConnect\Interfaces\IdentityEntityInterface;

/**
 * The claims the Hub can put in an id_token. The package's ClaimExtractor keeps only the ones the
 * granted scopes allow (profile -> name, email -> email + email_verified).
 */
class HubIdentityEntity implements IdentityEntityInterface
{
    use EntityTrait, WithClaims, WithCustomPermittedFor;

    public function __construct(private User $user) {}

    public function getClaims(array $scopes = []): array
    {
        return [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'email_verified' => $this->user->email_verified_at !== null,
        ];
    }
}
