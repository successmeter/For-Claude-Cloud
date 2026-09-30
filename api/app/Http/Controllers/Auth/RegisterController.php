<?php
// api/app/Http/Controllers/Auth/RegisterController.php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Hub\Http\Problem;
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
        if (! config('hub.open_registration')) {
            return Problem::response(404, 'registration_closed', 'Sign-up is by invitation.');
        }

        $data = $request->validate([
            'org_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            // MINOR finding #18 (final whole-branch review): uncompromised() checks the
            // password against Have I Been Pwned's k-anonymity range API (via Laravel's
            // container-bound Illuminate\Contracts\Validation\UncompromisedVerifier, see
            // Illuminate\Validation\NotPwnedVerifier) -- only a SHA-1 prefix ever leaves
            // this server, never the password itself. Testable without live network: each
            // registration test that must exercise the success path calls
            // Http::fake(['api.pwnedpasswords.com/*' => ...]) before hitting /api/register
            // (see RegistrationTest, StatefulOriginGatingTest).
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->uncompromised()],
        ]);

        $user = DB::transaction(function () use ($data) {
            $org = Org::create(['name' => $data['org_name']]);

            // A brand-new, unauthenticated registration request has no org yet, so no
            // tenant middleware has set app.current_org_id. The memberships table's RLS
            // policy has WITH CHECK (org_id::text = current_setting('app.current_org_id',
            // true)), so the Membership::create() below would fail with a 42501
            // WITH CHECK violation without this. The org now exists, so it's safe (and
            // necessary) to act as that org's tenant for the rest of this transaction
            // (transaction-local: it ends when this DB::transaction commits).
            TenantContext::set($org->id);

            // IMPORTANT finding #9 (final whole-branch review): User's 'password' => 'hashed'
            // cast (see App\Models\User) already hashes on assignment/save via Laravel's
            // hashed cast, which uses the configured Hash driver (bcrypt by default). Calling
            // bcrypt() here too double-hashed the password -- Hash::check() at login time
            // hashes the submitted plaintext once and compares against a value that was
            // hashed twice, so it would never match. Passing the plaintext directly lets the
            // cast do the (single) hashing.
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            Membership::create([
                'org_id' => $org->id,
                'user_id' => $user->id,
                'role' => 'owner',
            ]);

            return $user;
        });

        // The Org/User/Membership rows above are already committed at this point,
        // regardless of what happens below. Registration is a publicly callable
        // endpoint (unlike the SPA-only login/session flow), so it must not be
        // restricted to stateful origins -- but that means a request from a
        // non-stateful origin never had StartSession run on it (see bootstrap/app.php's
        // EnsureFrontendRequestsAreStateful gating), so $request->session() would throw
        // if called unconditionally. Without this check, such a request would 500 here
        // *after* the account was validly created, which is misleading (the write
        // succeeded despite the error response) and risks confused-retry duplicate
        // signups from any non-browser caller (curl, a mobile client, a future
        // integration). hasSession() reports whether a session store is actually bound
        // to this request, i.e. whether StartSession ran for it.
        if ($request->hasSession()) {
            auth()->login($user);

            // Session-fixation fix: rotate the session ID after establishing a new
            // authenticated session, matching LoginController::login()'s existing
            // pattern. Without this, a pre-login session ID (e.g. one an attacker
            // fixed via a shared link) would persist unchanged after registration,
            // letting the attacker hijack the now-authenticated session.
            $request->session()->regenerate();
        }

        // No TenantContext::clear() needed here any more (it was IMPORTANT finding #12's
        // mitigation): TenantContext::set() is transaction-local since Plan B Task 1, so the
        // org context ended when the DB::transaction above committed.

        return response()->json(['id' => $user->id], 201);
    }
}
