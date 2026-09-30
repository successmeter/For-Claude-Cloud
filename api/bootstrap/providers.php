<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Extends Laravel Passport's provider with OpenID Connect (id_token, discovery, JWKS).
    // Passport's own provider is excluded via composer.json extra.laravel.dont-discover.
    OpenIDConnect\Laravel\PassportServiceProvider::class,
];
