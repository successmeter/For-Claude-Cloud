<?php
// api/app/Hub/Models/WebhookEndpoint.php
namespace App\Hub\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    use HasUuids;

    protected $fillable = ['tool', 'url', 'secret', 'previous_secret', 'active'];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return [
            // Platform config rather than tenant data, so Laravel's APP_KEY encryption (like
            // users.mfa_secret) rather than the per-org envelope encryption.
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'active' => 'boolean',
        ];
    }
}
