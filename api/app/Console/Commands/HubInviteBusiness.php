<?php
// api/app/Console/Commands/HubInviteBusiness.php
namespace App\Console\Commands;

use App\Models\Org;
use App\Services\Audit\AuditLogger;
use App\Services\Invitations\InvitationIssuer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Staff create a business and invite its owner (Plan D design §3.1): the pilot's admin screen until
 * staff sign-in exists. Prints the link once and emails it.
 */
class HubInviteBusiness extends Command
{
    protected $signature = 'hub:invite-business {name : the business name} {email : the owner\'s email}';

    protected $description = 'Create a business and invite its owner';

    public function handle(InvitationIssuer $issuer, AuditLogger $audit): int
    {
        $name = trim((string) $this->argument('name'));
        $email = trim((string) $this->argument('email'));

        $validator = Validator::make(compact('name', 'email'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:254'],
        ]);
        if ($validator->fails()) {
            $this->error('Usage: hub:invite-business "<business name>" <owner email>');

            return self::FAILURE;
        }

        [$org, $link] = DB::transaction(function () use ($name, $email, $issuer, $audit) {
            $org = Org::create(['name' => $name]);
            $audit->record('org.created', 'org', $org->id, $org->id);
            [, $link] = $issuer->issue($org, $email, 'owner');

            return [$org, $link];
        });

        $this->info("Created {$org->name} ({$org->id}) and emailed an owner invitation to {$email}.");
        $this->line("Invitation link (shown once, valid ".InvitationIssuer::VALID_DAYS." days): {$link}");

        return self::SUCCESS;
    }
}
