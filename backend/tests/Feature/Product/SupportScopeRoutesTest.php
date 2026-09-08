<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SupportScope;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\Support\Database\RefreshDatabase;

/*
 * Que cada alcance de soporte abra lo que dice y NADA MAS, comprobado sobre las
 * rutas registradas (RF-PD-11, RL-19, ADR-020, regla dura 18).
 *
 * ## Por que esta prueba existe
 *
 * Desde que `actsAs()` devuelve `admin` para los tres alcances, **lo unico que
 * separa a `read_only` de `configuration` son sus ambitos**. Esa es una decision
 * correcta —el `auditor` de este producto no es «solo lectura de todo», sino el
 * rol de una funcion concreta, y con el `read_only` no alcanzaba ninguna
 * pantalla— pero deja toda la contencion en una sola de las dos comprobaciones
 * del §7.3.
 *
 * Asi que se comprueba, y no sobre una lista escrita a mano: se recorren las
 * rutas que la aplicacion tiene registradas de verdad. Una ruta de escritura
 * nueva que mañana cuelgue de `attendance:read` rompe esta prueba el mismo dia
 * en que se escribe, y no el dia en que alguien de soporte la use.
 *
 * **No sustituye a las pruebas de comportamiento** de `SupportTokenBehaviourTest`
 * —aquellas ejercitan peticiones reales con su policy— sino que cubre lo que
 * aquellas no pueden: TODA la superficie, incluida la que nadie penso en probar.
 */

/*
 * NO TOCA LA BASE DE DATOS, y aun asi declara `RefreshDatabase`.
 *
 * No es celo: la suite comparte el `migrate:fresh` que hace ese trait la primera
 * vez, y un fichero de `tests/Feature` que no lo declare deja el esquema sin
 * migrar para los que vengan detras segun el orden de ejecucion. Se descubrio
 * aqui, con «relation "sites" does not exist» en pruebas ajenas.
 */

uses(RefreshDatabase::class);
/**
 * Los ambitos que exige una ruta, tal y como los declara su middleware
 * `ability:` (`CheckForAnyAbility`: la lista separada por comas significa «o»).
 *
 * @return list<string>
 */
function abilitiesRequiredBy(Route $route): array
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (\is_string($middleware) && str_starts_with($middleware, 'ability:')) {
            return array_values(array_filter(explode(',', substr($middleware, \strlen('ability:')))));
        }
    }

    return [];
}

/**
 * Las rutas de la API que no son de solo lectura.
 *
 * @return list<Route>
 */
function writeRoutes(): array
{
    $rutas = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        $verbos = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);

        if ($verbos !== [] && str_starts_with($route->uri(), 'api/v1')) {
            $rutas[] = $route;
        }
    }

    return $rutas;
}

it('encuentra rutas de escritura que analizar', function (): void {
    // El control que impide que todo lo de abajo pase por vacio.
    expect(writeRoutes())->not->toBe([]);
})->group('RF-PD-11');

it('los ambitos de lectura de read_only no abren NINGUNA ruta que escriba', function (): void {
    // `attendance:read`, `employees:read` y `audit:read`. Si alguna ruta que no
    // sea `GET` aceptara cualquiera de los tres, `read_only` dejaria de ser de
    // solo lectura sin que nadie lo hubiera decidido.
    //
    // LA UNICA EXCEPCION, Y NO ESCRIBE NADA: `POST /api/v1/broadcasting/auth`.
    // Es la autorizacion de suscripcion al canal de presencia (ADR-011), y es
    // `POST` por protocolo, no porque cambie nada: lo dice el propio
    // `bootstrap/app.php` al darle `attendance:read` —«suscribirse al canal es
    // otra forma de leer lo mismo»— y el alcance por canal lo vuelve a
    // comprobar `routes/channels.php`. Que `read_only` la alcance es coherente
    // con que alcance `GET /api/v1/attendance/live`.
    //
    // Se declara como lista cerrada, no se ignora: cualquier otra que aparezca
    // rompe la prueba.
    $sinEfecto = ['GET|POST /api/v1/broadcasting/auth'];

    $lectura = array_diff(SupportScope::ReadOnly->abilities(), SupportScope::Diagnostics->abilities());

    $alcanzables = [];

    foreach (writeRoutes() as $route) {
        if (array_intersect($lectura, abilitiesRequiredBy($route)) !== []) {
            $alcanzables[] = implode('|', array_diff($route->methods(), ['HEAD'])).' /'.$route->uri();
        }
    }

    expect(array_diff($alcanzables, $sinEfecto))->toBe(
        [],
        'Estas rutas ESCRIBEN y aceptan un ambito de lectura de `read_only`: '
        .implode(', ', array_diff($alcanzables, $sinEfecto)),
    )->and($sinEfecto)->toHaveCount(1);
})->group('RF-PD-11', 'RL-19');

it('ningun alcance alcanza una ruta de escritura que no sea de configuracion', function (SupportScope $scope): void {
    // La otra mitad: `configuration` SI escribe, y solo debe escribir ajustes
    // —`settings:*` cubre configuracion, perfil de cumplimiento y quioscos
    // (§7.3, precisiones 4 y 5)—. Cualquier otra escritura alcanzable por
    // cualquiera de los tres alcances es un hallazgo.
    $permitidas = [
        // Lo que el contrato concede a `configuration`: la configuracion de la
        // instalacion, el perfil de cumplimiento y los quioscos (§7.3,
        // precisiones 4 y 5).
        'PATCH /api/v1/settings',
        'POST /api/v1/kiosk/pair/confirm',
        'POST /api/v1/devices/{uuid}/unpair',
        // ALCANZABLE POR AMBITO Y CERRADA POR LA POLICY, y aparece aqui
        // precisamente por eso. `settings:*` cubre tambien el perfil de
        // cumplimiento (§7.3, precision 4), asi que la ruta se alcanza; lo que la
        // cierra es `ComplianceProfilePolicy::update()`, que rechaza a los
        // actores de soporte.
        //
        // Y tiene que cerrarse: ahi viven los umbrales LEGALES y
        // `retention_years` (RL-01, RL-02, regla dura 14), que los fija la
        // jurisdiccion del cliente y su asesoria, no quien esta arreglando una
        // incidencia tecnica. Que el `403` lo ponga la policy y no el ambito es
        // el diseño de las dos comprobaciones funcionando, no un descuido — y lo
        // prueba `SupportTokenBehaviourTest`.
        'PATCH /api/v1/compliance-profile',
        // El paquete de diagnostico, que es lo que los tres alcances existen
        // para poder generar. Que un actor de soporte NO pueda pedirlo con
        // `include_personal_data` (RL-19) lo comprueba `SupportTokenBehaviourTest`.
        'POST /api/v1/diagnostics/bundle',
        // No escribe: es la autorizacion de suscripcion al canal de presencia
        // (ADR-011), `POST` por protocolo. Ver la prueba de arriba.
        'GET|POST /api/v1/broadcasting/auth',
        // LOS DOS DEL ASISTENTE DE PUESTA EN MARCHA viajan bajo `settings:*`, asi
        // que el AMBITO de `configuration` los alcanza; esta prueba solo mira el
        // middleware. Quien los cierra es la POLICY: `SetupPolicy` rechaza a todo
        // actor de soporte, porque `setup.complete` es irreversible y poner en
        // marcha la instalacion lo decide quien la contrata. La prueba de que un
        // token `configuration` recibe 403 esta en `SupportTokenBehaviourTest`.
        'PUT /api/v1/setup/steps/{step}',
        'POST /api/v1/setup/complete',
    ];

    $alcanzables = [];

    foreach (writeRoutes() as $route) {
        if (array_intersect($scope->abilities(), abilitiesRequiredBy($route)) !== []) {
            $alcanzables[] = implode('|', array_diff($route->methods(), ['HEAD'])).' /'.$route->uri();
        }
    }

    expect(array_diff($alcanzables, $permitidas))->toBe([]);
})->with(SupportScope::cases())->group('RF-PD-11', 'RL-19');

it('ningun alcance lleva un ambito que el contrato prohibe', function (SupportScope $scope): void {
    // La lista literal de la tabla de `POST /api/v1/support/grants`.
    foreach (['license:*', 'support:*', 'employees:*', 'credentials:*', 'attendance:correct', 'reports:*', 'reports:legal'] as $prohibido) {
        expect($scope->abilities())->not->toContain($prohibido);
    }
})->with(SupportScope::cases())->group('RF-PD-11', 'RL-19');
