<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
 * Sanctum — publicado a proposito y con UNA diferencia respecto al fichero del
 * paquete: `guard` vacio.
 *
 * ## Por que se publica
 *
 * Sin este fichero, la configuracion la pone el paquete y cambia con cada
 * actualizacion. Lo que hay debajo no es una preferencia: es la mitad de como se
 * autentica el producto, y tiene que estar en el repositorio para que un `composer
 * update` no pueda moverlo sin que nadie lo lea.
 *
 * ## `guard => []`, y esto es lo importante
 *
 * El valor del paquete es `['web']`. Con el, `Sanctum\Guard::__invoke()` pregunta
 * PRIMERO al guard de sesion y solo despues mira el `Bearer`. Cuando aquel
 * responde, Sanctum adjunta un `TransientToken`, cuyo `can()` **devuelve `true`
 * para cualquier ambito**: el middleware `ability` —la mitad de la regla dura 18
 * que verifica el AMBITO del token— dejaria pasar todo.
 *
 * Aqui no hay ninguna SPA de mismo origen con cookies: las tres aplicaciones
 * cliente hablan con `/api/v1` por `Authorization: Bearer` (doc 02 §7.3), el
 * producto no expone `/sanctum/csrf-cookie` y ninguna ruta de `api_v1.php` lleva
 * el stack `web`. Un guard de sesion consultado antes que el token es, en ese
 * cuadro, solo una via por la que una cookie residual —o una sesion abierta por
 * un futuro panel servido por Laravel— se convertiria en un token sin limites de
 * ambito.
 *
 * **Lo que NO cambia:** `Sanctum::authenticateAccessTokensUsing()` sigue siendo
 * quien decide si un token vale (cuenta desactivada, quiosco revocado, empleado de
 * baja o PIN restablecido), y se ejecuta igual — es el callback del camino del
 * `Bearer`, que es el unico que queda.
 *
 * ## `expiration => null`, y tampoco es un descuido
 *
 * La caducidad se pasa TOKEN A TOKEN (`SanctumAccessTokenIssuer`,
 * `SanctumDeviceTokenIssuer`): la sesion de gestion es corta (§7.3), la del portal
 * dura dos horas (ADR-015) y la del quiosco 90 dias con rotacion (RF-ID-04). Un
 * valor global aqui **sobreescribiria los tres** y obligaria a elegir cual se
 * rompe.
 */

return [

    /*
     * Dominios con sesion. Ninguno, por lo dicho arriba: el producto no tiene
     * clientes de primera parte con cookies. Se deja explicito y vacio en lugar
     * de heredar la lista de `localhost` del paquete.
     */
    'stateful' => [],

    /*
     * Ningun guard antes del `Bearer`. Ver el bloque de arriba: con `web`, un
     * `TransientToken` diria que si a cualquier ambito.
     */
    'guard' => [],

    /*
     * Sin caducidad global: la pone cada emisor, token a token.
     */
    'expiration' => null,

    /*
     * Prefijo de los tokens emitidos. Vacio de serie —el valor del paquete—:
     * sirve para que los escaneres de secretos de las plataformas publicas
     * reconozcan un token filtrado, y este producto se instala en el servidor del
     * cliente (ADR-016), no en un repositorio publico.
     */
    'token_prefix' => (string) env('SANCTUM_TOKEN_PREFIX', ''),

    /*
     * Los tres middlewares que Sanctum usa en el camino de sesion. Se conservan
     * con su valor del paquete aunque `stateful` este vacio: son referencias de
     * clase, no comportamiento, y quitarlas romperia el paquete si algun dia se
     * vuelve a usar ese camino.
     */
    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
