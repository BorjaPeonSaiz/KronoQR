<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as Router;
use Tests\Support\Database\RefreshDatabase;

/*
 * Que TODA ruta autenticada de la API declare su zona de limitacion de
 * aplicacion, comprobado sobre el router real (RS-02, RS-04, RS-05, H-01 y H-02
 * de la revision interna ASVS de 2026-09).
 *
 * ## Por que esta prueba existe
 *
 * `bootstrap/app.php` **no** aplica `throttleApi()` a proposito: pondria
 * `throttle` tambien sobre `/health` y `/ready`, y una sonda de VIDA que
 * consulta Redis para saber si puede responder hace que Docker reinicie PHP
 * cuando el que se cae es Redis. El precio de esa decision correcta es que cada
 * ruta declara su zona **a mano**, y lo que se hace a mano se olvida: el grupo
 * `employees:*` entero llevaba dos fases sin zona y nadie lo noto, porque una
 * ruta sin limite no rompe nada — hasta que alguien la usa.
 *
 * Nginx no cubre ese hueco. Limita por ORIGEN (§7.1) y no sabe que token trae la
 * peticion: en un hotel, una cuenta de gestion comprometida y el panel legitimo
 * salen por la misma IP. La zona de aplicacion es la unica capa que cuenta por
 * CUENTA, y es la que convierte «he robado un token de `rrhh`» en «puedo
 * descargarme la plantilla entera a la velocidad de la red».
 *
 * Asi que la lista no se escribe a mano: se recorre `Router::getRoutes()`. Una
 * ruta nueva sin zona rompe esta prueba **el dia en que se escribe**.
 *
 * ## Feature y no Architecture
 *
 * `tests/Pest.php:19` solo extiende el `TestCase` de Laravel en `Feature`,
 * `Integration` y `Contract`. Sin framework arrancado no hay router registrado y
 * no hay nada que recorrer. El precedente exacto es
 * `Tests\Feature\Product\SupportScopeRoutesTest`, que recorre el mismo arbol con
 * el mismo criterio.
 *
 * ## Lo que esta prueba NO afirma
 *
 * Que el numero de la zona sea el adecuado. Eso es comportamiento y lo prueba
 * `Tests\Feature\Identity\ManagementRateLimitTest` agotando el cupo de verdad.
 * Aqui solo se comprueba que la zona **existe**, que es la condicion previa.
 */

/*
 * NO TOCA LA BASE DE DATOS, y aun asi declara `RefreshDatabase`, por lo mismo
 * que `SupportScopeRoutesTest`: la suite comparte el `migrate:fresh` que hace
 * ese trait la primera vez, y un fichero de `tests/Feature` que no lo declare
 * deja el esquema sin migrar para los que vengan detras segun el orden de
 * ejecucion.
 */
uses(RefreshDatabase::class);

/**
 * Las rutas de la API que exigen una sesion: las que llevan `auth:sanctum`.
 *
 * Las publicas quedan fuera a proposito. `/health` y `/ready` no llevan guarda
 * —una sonda de vida no debe depender de la cache— y las tres de emparejamiento
 * (`/kiosk/pair`, `/pair/claim`, `/pair/confirm` sin sesion) tienen sus propias
 * zonas declaradas y sus propias pruebas; lo que aqui se vigila es el hueco que
 * de verdad aparecio, que es el de las rutas de gestion.
 *
 * @return list<Route>
 */
function rutasConSesion(): array
{
    $rutas = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1') && \in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
            $rutas[] = $route;
        }
    }

    return $rutas;
}

/**
 * La zona de limitacion que declara una ruta, o cadena vacia si no declara
 * ninguna.
 *
 * Se lee de `gatherMiddleware()` y no de la definicion del grupo para que una
 * zona heredada del grupo cuente igual que una declarada en la ruta: lo que
 * importa es lo que llega a ejecutarse.
 */
function zonaDeLimiteDe(Route $route): string
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (\is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
            return substr($middleware, \strlen('throttle:'));
        }
    }

    return '';
}

/**
 * Como se nombra una ruta en el mensaje de fallo: los verbos y la URI, que es lo
 * que hay que ir a buscar a `routes/api_v1.php`.
 */
function nombreDeRuta(Route $route): string
{
    return implode('|', array_diff($route->methods(), ['HEAD'])).' /'.$route->uri();
}

/**
 * Las rutas autenticadas que no declaran zona y tampoco estan exentas.
 *
 * El recorrido vive aqui y no en la prueba por el §3.5: un bucle con un `if`
 * dentro de un test es una rama que nadie prueba, y ademas esconde cual de los
 * elementos fallo. La prueba se queda con una lista y una aseveracion.
 *
 * @param  list<string>  $exentas  URIs exentas, tal y como las declara el router
 * @return list<string>
 */
function rutasSinZonaDeLimite(array $exentas): array
{
    $sinZona = [];

    foreach (rutasConSesion() as $route) {
        $declarada = zonaDeLimiteDe($route) !== '';
        $exenta = \in_array($route->uri(), $exentas, true);

        $sinZona[] = $declarada || $exenta ? null : nombreDeRuta($route);
    }

    $sinZona = array_values(array_filter($sinZona));
    sort($sinZona);

    return $sinZona;
}

/**
 * Las zonas que el router usa y que ningun `RateLimiter::for()` declara.
 *
 * @return list<string>
 */
function zonasDeLimiteHuerfanas(): array
{
    $huerfanas = [];

    foreach (rutasConSesion() as $route) {
        $zona = zonaDeLimiteDe($route);
        $declarada = $zona === '' || RateLimiter::limiter($zona) !== null;

        $huerfanas[] = $declarada ? null : $zona.' ('.nombreDeRuta($route).')';
    }

    $huerfanas = array_values(array_unique(array_filter($huerfanas)));
    sort($huerfanas);

    return $huerfanas;
}

it('encuentra rutas autenticadas que analizar', function (): void {
    // El control que impide que lo de abajo pase por vacio: si el router no se
    // registrara, las dos pruebas siguientes darian verde sobre una lista de
    // cero elementos.
    expect(rutasConSesion())->not->toBe([]);
})->group('RS-02', 'RS-04', 'RS-05');

it('exige una zona de limitacion a toda ruta autenticada de la API', function (): void {
    /*
     * LA UNICA EXENCION, Y CON MOTIVO ESCRITO (decision 11 de la ficha 3.8).
     *
     * `POST /api/v1/auth/logout` revoca **el token del que llama** y nada mas:
     * no lleva `ability:`, acepta a proposito la sesion pendiente de segundo
     * factor y no lee ni escribe ningun dato del cliente. Un techo ahi no frena
     * ningun abuso —quien agota el cupo solo se cierra la sesion a si mismo— y
     * si produce un dano real: deja a una persona **sin poder cerrar sesion** en
     * el momento en que mas lo necesita, que es justo cuando sospecha que su
     * token esta comprometido.
     *
     * Es lista cerrada, no una excepcion abierta: cualquier otra ruta que
     * aparezca sin zona rompe esta prueba, y la cuenta de abajo impide que la
     * lista crezca sin que alguien lo decida.
     */
    $exentas = ['api/v1/auth/logout'];

    $sinZona = rutasSinZonaDeLimite($exentas);

    expect($sinZona)->toBe(
        [],
        \count($sinZona).' ruta(s) autenticada(s) sin zona `throttle:`, que es el hueco H-01 de la revision '
        .'interna: '.implode(', ', $sinZona),
    )->and($exentas)->toHaveCount(1);
})->group('RS-02', 'RS-04', 'RS-05');

it('exige que cada zona usada por el router tenga su limitador registrado', function (): void {
    // La otra mitad, y la que falla en silencio: `throttle:inventada` no revienta
    // al registrar la ruta. Laravel resuelve el limitador en la PETICION, asi que
    // una zona mal escrita se descubre con un `500` en produccion, o —peor— con
    // un limite que nunca llega porque el nombre no coincide con el que declara
    // el `ServiceProvider`.
    $huerfanas = zonasDeLimiteHuerfanas();

    expect($huerfanas)->toBe(
        [],
        'Zona(s) de limitacion sin `RateLimiter::for()` que las declare: '.implode(', ', $huerfanas),
    );
})->group('RS-02', 'RS-04', 'RS-05');

/*
 * ---------------------------------------------------------------------------
 * La descarga de un informe en diferido (tarea 3.9, RF-IN-06, ADR-041)
 * ---------------------------------------------------------------------------
 *
 * Es la unica ruta de la API **sin `auth:sanctum`** que reparte datos de la
 * plantilla, asi que el recorrido de arriba no la ve: aquel enumera las rutas
 * con sesion. Y precisamente por no tener sesion es donde una zona olvidada
 * costaria mas caro — no hay cuenta por la que contar, y lo unico que queda
 * entre un `uuid` conocido y una prueba de tokens a la velocidad de la red es
 * este techo por origen.
 *
 * Se comprueba aparte y con nombre, no por el recorrido general, para que el dia
 * que alguien la mueva al grupo autenticado —o le quite el `throttle`— esta
 * prueba lo diga con una frase y no con una lista.
 */
it('exige zona propia por IP a la descarga de informes en diferido, que no lleva sesion', function (): void {
    $descarga = null;

    foreach (Router::getRoutes()->getRoutes() as $route) {
        if ($route->uri() === 'api/v1/reports/exports/{uuid}/download') {
            $descarga = $route;
        }
    }

    expect($descarga)->not->toBeNull('La ruta de descarga de informes en diferido ha desaparecido del router.');

    /** @var Route $descarga */
    expect(zonaDeLimiteDe($descarga))->toBe(
        'report-download',
        'La descarga de informes en diferido va sin sesion (ADR-041): sin zona propia, lo unico que la '
        .'frena es Nginx, que cuenta por origen y en un hotel es un cubo compartido por NAT.',
    );

    // Y la zona existe de verdad: `throttle:inventada` no revienta al registrar la
    // ruta, se descubre con un `500` en produccion.
    expect(RateLimiter::limiter('report-download'))->not->toBeNull();

    // La otra mitad de la decision: **no** lleva `auth:sanctum`, y eso es
    // deliberado (ADR-041). Si alguien se la pusiera, el enlace de un solo uso
    // dejaria de poder abrirse con un clic y el panel tendria que traerse el
    // fichero entero en memoria.
    expect($descarga->gatherMiddleware())->not->toContain('auth:sanctum');
})->group('RS-02', 'RF-IN-06');
