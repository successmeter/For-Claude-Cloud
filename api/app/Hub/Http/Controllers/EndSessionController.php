<?php
// api/app/Hub/Http/Controllers/EndSessionController.php
namespace App\Hub\Http\Controllers;

use App\Hub\Identity\FirstPartyClient;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\InvalidTokenStructure;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * OIDC RP-initiated logout. The client proves which client it is with an id_token it was issued
 * (signature checked against the Hub's key; an expired token is fine, since that is the usual
 * case at logout), and may only send the browser back to one of its registered
 * post_logout_redirect_uris. Anything else is a 400 and no redirect.
 */
class EndSessionController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = $this->verifiedHint((string) $request->query('id_token_hint'));
        $client = $token ? FirstPartyClient::find($token->claims()->get('aud')[0] ?? null) : null;
        $target = (string) $request->query('post_logout_redirect_uri');

        if (! $client || ! in_array($target, $client->post_logout_redirect_uris ?? [], true)) {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'A valid id_token_hint and a registered post_logout_redirect_uri are required.',
            ], 400);
        }

        if (Auth::guard('web')->check()) {
            app(AuditLogger::class)->record('logout', 'user', (string) Auth::id(), null, ['channel' => 'oidc']);
            Auth::guard('web')->logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $state = $request->query('state');

        if ($state !== null) {
            $target .= (str_contains($target, '?') ? '&' : '?').http_build_query(['state' => $state]);
        }

        return redirect()->away($target);
    }

    private function verifiedHint(string $jwt): ?UnencryptedToken
    {
        if ($jwt === '') {
            return null;
        }

        try {
            $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText('unused'), $this->publicKey());
            $token = $config->parser()->parse($jwt);
        } catch (InvalidTokenStructure|Throwable) {
            return null;
        }

        return ($token instanceof UnencryptedToken && $config->validator()->validate($token, new SignedWith($config->signer(), $config->verificationKey())))
            ? $token
            : null;
    }

    /** Same lookup Passport uses for its own key: env value first, then the key file. */
    private function publicKey(): InMemory
    {
        $key = str_replace('\\n', "\n", (string) config('passport.public_key'));

        return $key !== ''
            ? InMemory::plainText($key)
            : InMemory::file(Passport::keyPath('oauth-public.key'));
    }
}
