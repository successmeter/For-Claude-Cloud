<?php
// api/app/Http/Controllers/Auth/RegisterController.php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'org_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $user = DB::transaction(function () use ($data) {
            $org = Org::create(['name' => $data['org_name']]);

            // A brand-new, unauthenticated registration request has no X-Org-Id header
            // and no current_org_id request attribute, so SetTenantContext leaves the
            // Postgres session's app.current_org_id unset. The memberships table's RLS
            // policy has WITH CHECK (org_id::text = current_setting('app.current_org_id',
            // true)), so the Membership::create() below would fail with a 42501
            // WITH CHECK violation without this. The org now exists, so it's safe (and
            // necessary) to act as that org's tenant for the rest of this transaction.
            TenantContext::set($org->id);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => bcrypt($data['password']),
            ]);

            Membership::create([
                'org_id' => $org->id,
                'user_id' => $user->id,
                'role' => 'owner',
            ]);

            return $user;
        });

        auth()->login($user);

        // Session-fixation fix: rotate the session ID after establishing a new
        // authenticated session, matching LoginController::login()'s existing pattern.
        // Without this, a pre-login session ID (e.g. one an attacker fixed via a shared
        // link) would persist unchanged after registration, letting the attacker
        // hijack the now-authenticated session.
        $request->session()->regenerate();

        return response()->json(['id' => $user->id], 201);
    }
}
