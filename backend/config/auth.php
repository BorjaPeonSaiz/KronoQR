<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        /*
         * El guard con el que se autentica la API (tarea 1.6). Sanctum lo
         * registraria solo, pero se declara aqui a proposito para que sea
         * visible: es la puerta de todo el producto.
         *
         * **`provider` a `null`, y no es un descuido.** En este producto los
         * tokens cuelgan de DOS modelos: las cuentas de gestion (`users`, tarea
         * 1.6) y **los quioscos** (`devices`, RF-ID-04, tarea 1.5). Sanctum
         * comprueba en `Guard::hasValidProvider()` que el `tokenable` sea del
         * modelo del proveedor declarado; con `users` fijo aqui, **ningun token
         * de dispositivo se autenticaria jamas** y `POST /api/v1/scan`
         * devolveria 401 a un quiosco perfectamente emparejado. Con `null`, la
         * comprobacion se salta y quien decide sigue siendo el token: su hash,
         * su caducidad y sus *abilities*.
         *
         * Lo que se pierde con `null` es la comprobacion de modelo, que aqui no
         * aporta nada: un token solo existe si alguien lo emitio contra su
         * `tokenable`, y la autorizacion real son el ambito (middleware
         * `ability`) y la policy de cada endpoint (regla dura 18, doc 02 §7.3).
         * `ScanPolicy` comprueba explicitamente que quien porta el token es un
         * dispositivo, y `EmployeePolicy` que es una persona con rol.
         *
         * El guard de los roles de Spatie sigue siendo explicito mas abajo
         * (`permission.php`), que era el problema que este bloque documentaba
         * antes: sin eso, los roles se resuelven con un guard distinto del que
         * los creo y todo devuelve 403 sin explicacion.
         */
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            // El modelo de usuario es de Identity, no de App\Models: los
            // modelos Eloquent viven en Infrastructure/Persistence del modulo
            // que los posee (doc 02 §1.6). La clase existe desde la tarea 1.6.
            // Se deja como cadena porque este fichero es configuracion y no
            // puede importar codigo de un modulo (Deptrac).
            'model' => env('AUTH_MODEL', 'App\Modules\Identity\Infrastructure\Persistence\User'),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
