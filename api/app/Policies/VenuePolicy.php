<?php
// api/app/Policies/VenuePolicy.php
namespace App\Policies;

use App\Models\Membership;
use App\Models\User;
use App\Models\Venue;

class VenuePolicy
{
    private function roleFor(User $user, Venue $venue): ?string
    {
        return Membership::where('org_id', $venue->org_id)
            ->where('user_id', $user->id)
            ->value('role');
    }

    public function view(User $user, Venue $venue): bool
    {
        return $this->roleFor($user, $venue) !== null;
    }

    public function update(User $user, Venue $venue): bool
    {
        return in_array($this->roleFor($user, $venue), ['owner', 'manager'], true);
    }

    public function delete(User $user, Venue $venue): bool
    {
        return $this->roleFor($user, $venue) === 'owner';
    }
}
