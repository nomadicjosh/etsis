<?php

declare(strict_types=1);

use Codefy\Framework\Configuration\Middleware;
use Codefy\Framework\Support\CodefyServiceProvider;

use function Codefy\Framework\Helpers\env;

return [
    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'name' => env(key: 'APP_NAME', default: 'etsis'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    */

    'env' => env(key: 'APP_ENV', default: 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    */
    'debug' => env(key: 'APP_DEBUG', default: false),

    /*
    |--------------------------------------------------------------------------
    | Application Base Url
    |--------------------------------------------------------------------------
    */
    'url' => env(key: 'APP_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */
    'timezone' => 'America/Los_Angeles',

    /*
    |--------------------------------------------------------------------------
    | Application Locale
    |--------------------------------------------------------------------------
    */
    'locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Application HTML Charset
    |--------------------------------------------------------------------------
    */
    'charset' => 'UTF-8',

    /*
    |--------------------------------------------------------------------------
    | Application HTML Language
    |--------------------------------------------------------------------------
    */
    'language' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Domain
    |--------------------------------------------------------------------------
    */
    'locale_domain' => 'codefy',

    /*
    |--------------------------------------------------------------------------
    | API key for restful routes.
    |--------------------------------------------------------------------------
    */
    'api_key' => env(key: 'APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    */
    'crypto_key' => is_file(__DIR__ . '/../.enc.key') ? trim(file_get_contents(__DIR__ . '/../.enc.key')) : '',

    /*
    |--------------------------------------------------------------------------
    | Event Listener Provider and Dispatcher
    |--------------------------------------------------------------------------
    */
    'event_listener' => Qubus\EventDispatcher\Providers\PrioritizedProvider::class,
    'event_dispatcher' => Qubus\EventDispatcher\EventDispatcher::class,

    /*
    |--------------------------------------------------------------------------
    | Controller Namespace
    |--------------------------------------------------------------------------
    */
    'controller_namespace' => 'Application\\Http\\Controller',

    /*
    |--------------------------------------------------------------------------
    | Application Configured Service Providers
    |--------------------------------------------------------------------------
    | These service providers will automatically load when the application is
    | requested. Feel free to add your own service providers.
    */
    'providers' => CodefyServiceProvider::defaultProviders()->merge([
        // Application Service Providers...
        // App\Infrastructure\Providers\AppServiceProvider::class,
    ])->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Middleware Aliases
    |--------------------------------------------------------------------------
    | Middleware aliases are registered here, but to use a middleware, you
    | can add them to a route, a group of routes or controllers.
    */
    'middlewares' => Middleware::defaultMiddlewares()->merge([
        'sis.json' => Application\Http\Middleware\WorkspaceJsonBodyMiddleware::class,
        'sis.privacy' => Application\Http\Middleware\SisPrivacyMiddleware::class,
        'php.debugbar' => Application\Http\Middleware\PrivateRouteDebugBarMiddleware::class,
        'http.exception' => Application\Http\Middleware\HttpExceptionMiddleware::class,
    ])->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Base Middlewares
    |--------------------------------------------------------------------------
    | Register middleware class strings or aliases to be applied to the entire
    | application.
    */
    'base_middlewares' => array_merge(
        [
            'sis.privacy',
            'sis.json',
            'cors',
            'http.exception',
            'firewall',
            'csrf.token',
            'csrf.protection',
            'http.cache.prevention',
            'user.cookie.decrypt',
            'bind.request',
        ],
        env(key: 'APP_DEBUG', default: false) === true ? ['php.debugbar'] : [],
    ),

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Console Commands
    |--------------------------------------------------------------------------
    | These console commands will automatically load when the application is
    | requested. Feel free to add your own console commands.
    */
    'commands' => [
        \Application\Console\Commands\BootstrapAccessCommand::class,
        \Application\Console\Commands\MoodleCheckCommand::class,
    ]
];
