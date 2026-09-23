<?php
// api/app/Models/Org.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Org extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['name'];

    public function venues()
    {
        return $this->hasMany(Venue::class);
    }

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }
}
