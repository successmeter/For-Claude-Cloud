<?php
// api/app/Models/Membership.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use HasUuids;

    protected $fillable = ['org_id', 'user_id', 'role'];

    public function org()
    {
        return $this->belongsTo(Org::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
