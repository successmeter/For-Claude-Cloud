<?php
// api/app/Http/Controllers/Auth/MfaController.php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PragmaRX\Google2FA\Google2FA;

class MfaController extends Controller
{
    public function enroll(Request $request)
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        // forceFill()->save() rather than update(): mfa_secret/mfa_enabled are
        // deliberately excluded from User's #[Fillable] list (see User model), so a
        // plain mass-assigning update() would silently no-op and never persist these
        // columns at all.
        $request->user()->forceFill([
            'mfa_secret' => $secret,
            'mfa_enabled' => false,
        ])->save();

        return response()->json(['secret' => $secret]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($request->user()->mfa_secret, $data['code']);

        if (! $valid) {
            return response()->json(['message' => 'Invalid code'], 422);
        }

        $request->user()->forceFill(['mfa_enabled' => true])->save();

        return response()->noContent();
    }
}
