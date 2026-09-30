<?php

return [
    'passport' => [

        /**
         * Place your Passport and OpenID Connect scopes here.
         * To receive an `id_token, you should at least provide the openid scope.
         */
        'tokens_can' => [
            'openid' => 'Enable OpenID Connect',
            'profile' => 'Information about your profile',
            'email' => 'Information about your email address',
            // Hub contract v1 scopes (design §4.1). phone/address from the package default are
            // not offered: the Hub holds neither.
            'orgs' => 'Your organisations and roles',
            'competitor-sets:read' => 'Read competitor sets',
            'competitor-sets:write' => 'Change competitor sets',
        ],
    ],

    /**
     * Place your custom claim sets here.
     */
    'custom_claim_sets' => [
        // 'login' => [
        //     'last-login',
        // ],
        // 'company' => [
        //     'company_name',
        //     'company_address',
        //     'company_phone',
        //     'company_email',
        // ],
    ],

    /**
     * You can override the repositories below.
     */
    'repositories' => [
        // id_token.sub is users.public_id, never the bigint users.id (Plan B design §4.1).
        'identity' => \App\Hub\Identity\HubIdentityRepository::class,
    ],

    'routes' => [
        /**
         * When set to true, this package will expose the OpenID Connect Discovery endpoint.
         *  - /.well-known/openid-configuration
         */
        'discovery' => true,
        /**
         * When set to true, this package will expose the JSON Web Key Set endpoint.
         */
        'jwks' => true,
         /**
          * Optional URL to change the JWKS path to align with your custom Passport routes.
          * Defaults to /oauth/jwks
          */
        'jwks_url' => '/oauth/jwks',
        /**
         * When set to true, this package will expose the UserInfo endpoint at /oauth/userinfo.
         * The endpoint is protected by Passport's auth:api guard and returns claims
         * for the authenticated user filtered by the access token's granted scopes.
         */
        'userinfo' => false,
    ],

    /**
     * Settings for the discovery endpoint
     */
    'discovery' => [
        /**
        * Hide scopes that aren't from the OpenID Core spec from the Discovery,
        * default = false (all scopes are listed)
        */
        'hide_scopes' => false,
    ],

    /**
     * The signer to be used
     */
    'signer' => \Lcobucci\JWT\Signer\Rsa\Sha256::class,

    /**
     * Optional associative array that will be used to set headers on the JWT
     */
    'token_headers' => [],

    /**
     * By default, microseconds are included.
     */
    'use_microseconds' => false,

    /**
     * Value for the issuedBy params. By default: laravel to get the scheme and host from the $_SERVER variable.
     * Options: laravel (use Request to extract scheme and host), server (use $_SERVER to detect)
     * or another string that will be used as-is
     */
    // A fixed issuer in production (HUB_ISSUER) stops a Host-header change from changing `iss`.
    'issuedBy' => env('HUB_ISSUER') ?: 'laravel',

    /**
     * By default, https is enforce. You can disable it here.
     */
    'forceHttps' => env('OPENID_FORCE_HTTPS', true),
];
