<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;
use function Codefy\Framework\Helpers\trans;

return [
    /*
    |--------------------------------------------------------------------------
    | User session cookie name.
    |--------------------------------------------------------------------------
    */
    'cookie_name' => 'USERSESSID',

    'login_route' => env(key: 'AUTH_LOGIN_ROUTE', default: 'login'),

    'login_url' => sprintf(
        rtrim(env(key: 'APP_BASE_URL'), '/') . '/%s/',
        env(key: 'AUTH_LOGIN_ROUTE', default: 'login')
    ),

    /** Where should users be redirected when authentication fails? */
    'http_redirect' => sprintf('/%s/', env(key: 'AUTH_LOGIN_ROUTE', default: 'login')),

    'admin_url' => rtrim(env(key: 'APP_BASE_URL'), '/') . '/admin/',

    'pdo' => [
        /** name of the user's table */
        'table' => 'users',

        'fields' => [
            /** field name to use for identity (email, username, token) */
            'identity' => 'email',
            /** name of the role field */
            'role' => 'role',
            /** name of the token field */
            'token' => 'token',
            /** name of the password field */
            'password' => 'password',
        ],

    ],

    'redirect_guests_to' => sprintf('/%s/', env(key: 'AUTH_LOGIN_ROUTE', default: 'login')),

    'password_min_length' => 26,

    'username_min_length' => 6,

    'access_denied_message' => trans('Access denied.'),
];
