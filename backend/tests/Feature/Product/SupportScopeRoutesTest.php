<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SupportScope;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

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

/*
 * El centro y la licencia, que la mitad de LECTURA si necesita.
 *
 * La de escritura no toca la base de datos —solo recorre el router— y por eso
 * este fichero no tenia `beforeEach`. Las pruebas de lectura piden cada ruta de
 * verdad con un token de soporte, y sin licencia instalada las funcionalidades
 * accesorias se degradan (ADR-019): una ruta degradada responderia algo que no
 * es `403` por un motivo que no tiene nada que ver con la autorizacion.
 */
beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

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
        /*
         * LA EXPORTACION INTEGRA (RF-PD-14, RL-20, tarea 5.10), y de todas las de
         * esta lista es la que MAS importa que este cerrada.
         *
         * Viaja bajo `settings:*` porque no sale hacia el fabricante: es el
         * cliente llevandose lo suyo, y un ambito propio seria una potestad que
         * nadie concederia por separado. El AMBITO de `configuration` la alcanza,
         * asi que aparece aqui; lo que la cierra es `DataExportPolicy`, que
         * rechaza a todo actor de soporte.
         *
         * Si esa policy se cayera, un token de soporte podria llevarse una copia
         * completa de la plantilla del hotel, de cuatro años de fichajes y de las
         * cuentas de gestion: la escalada mas grave que este producto puede
         * tener, y exactamente lo que la regla dura 16 y ADR-020 hacen imposible.
         * Los tres alcances tienen su prueba de `403` en
         * `DataExportAuthorizationTest`.
         */
        'POST /api/v1/data-export',
        /*
         * RESOLVER UN GRUPO DEL HISTORICO DE ERRORES (RF-PD-15, tarea 5.12).
         *
         * Viaja bajo `diagnostics:*` —el mismo ambito que el paquete, porque es
         * la misma potestad: diagnosticar—, asi que **los tres alcances lo
         * alcanzan por el middleware**. Y tiene que ser asi: negar ese ambito
         * dejaria a un acceso de soporte sin poder LEER el historico, que es
         * justamente para lo que se concede el alcance `diagnostics`.
         *
         * Lo que cierra la escritura es `ErrorEventPolicy::resolve()`, que
         * rechaza a todo actor de soporte: dar un fallo por atendido en la
         * instalacion de un cliente es una decision del cliente (ADR-020). La
         * prueba de que los tres alcances reciben `403` esta en
         * `ErrorEventAuthorizationTest`.
         *
         * Lo que se perderia si esa policy se cayera es acotado —el fabricante
         * podria vaciar la bandeja de errores del cliente— pero es exactamente
         * el tipo de decision que la regla dura 16 reserva al cliente.
         */
        'POST /api/v1/diagnostics/errors/{id}/resolve',
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

/*
 * ---------------------------------------------------------------------------
 * LA MITAD DE LECTURA (cierre de la Fase 3)
 * ---------------------------------------------------------------------------
 *
 * Todo lo de arriba pregunta «¿que ESCRIBE un alcance de soporte?», y durante
 * tres fases esa fue la unica pregunta que se hizo. Por eso nadie vio que un
 * token `read_only` leia `GET /api/v1/absences` entera —con `type: sick_leave`
 * y con la nota—, que es **dato de salud del art. 9 del RGPD** servido al
 * fabricante (regla dura 16, ADR-020, RL-19).
 *
 * No fallo ninguna de las dos comprobaciones del §7.3: el ambito abria porque
 * `employees:read` tiene que abrir, y la policy abria porque `actsAs()` devuelve
 * `admin` y `AbsencePolicy` no preguntaba quien actuaba. Fallo que **nadie
 * enumeraba la superficie de lectura**.
 *
 * ## Como se comprueba, y por que no basta con una lista
 *
 * La lista cerrada de abajo dice lo que el fabricante PUEDE leer, con el motivo
 * escrito de cada linea. Pero una ruta no entra en la comparacion por estar o no
 * en la lista: entra **si la aplicacion la deja pasar de verdad**. Cada ruta de
 * lectura que el ambito alcanza se pide con un token de soporte real, y las que
 * responden `403` quedan fuera porque las cierra su policy.
 *
 * Esa diferencia es todo el valor de la prueba:
 *
 * - Las dos de ausencias **no estan en la lista**, y no hacen falta: las cierra
 *   `AbsencePolicy` y por eso no llegan a compararse. El dia que alguien quite
 *   el `isSupportActor()` de esa policy, dejaran de responder `403`, apareceran
 *   en la comparacion y esta prueba se pondra roja **sin que haya que acordarse
 *   de nada**. Una lista de exclusiones no haria eso: seguiria verde.
 * - Y al reves: una ruta de lectura nueva con dato personal que nadie cierre
 *   aparece el mismo dia en que se escribe, y hay que decidir explicitamente si
 *   el fabricante la lee. Que es la decision que con `audit:read` ya quedo
 *   anotada como riesgo abierto en el docblock de `SupportScope::ReadOnly`.
 *
 * **No sustituye a `AbsenceSupportAccessTest`**, que es quien prueba el `403`
 * con la baja medica y la nota dentro y quien comprueba que no queda asiento de
 * divulgacion. Esta enumera; aquella ejercita.
 */

/**
 * Las rutas de la API que SOLO leen.
 *
 * `GET|POST /api/v1/broadcasting/auth` queda fuera por llevar `POST`: la trata
 * la mitad de escritura, donde su excepcion esta razonada.
 *
 * @return list<Route>
 */
function supportReadRoutes(): array
{
    $rutas = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        $verbos = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

        if ($verbos === ['GET'] && str_starts_with($route->uri(), 'api/v1')) {
            $rutas[] = $route;
        }
    }

    return $rutas;
}

/**
 * Las rutas de lectura que el AMBITO de un alcance alcanza.
 *
 * @return list<Route>
 */
function supportReadRoutesReachableBy(SupportScope $scope): array
{
    $rutas = [];

    foreach (supportReadRoutes() as $route) {
        if (array_intersect($scope->abilities(), abilitiesRequiredBy($route)) !== []) {
            $rutas[] = $route;
        }
    }

    return $rutas;
}

/**
 * El camino concreto con el que se pide cada ruta de lectura, por su plantilla.
 *
 * LOS IDENTIFICADORES SON REALES a proposito. Con un UUID inventado, una ruta
 * que resolviera el modelo antes de autorizar responderia `404` en vez de `403`
 * y esta prueba la contaria como abierta —o como cerrada— por el motivo
 * equivocado. Con la fila delante, el unico motivo posible de un `403` es la
 * policy.
 *
 * Las que no aparecen aqui se piden tal cual: no llevan parametros ni exigen
 * rango de fechas.
 *
 * @return array<string, string>
 */
function supportReadPaths(): array
{
    $siteId = WorkforceFixtures::onlySiteId();
    $employeeUuid = WorkforceFixtures::employee($siteId);

    /** @var int|string|null $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $employeeUuid)->value('id');

    $absenceUuid = Str::uuid7()->toString();

    // Una baja medica con nota: el peor caso que la pantalla de ausencias puede
    // servir, y el que tiene que quedarse dentro de la instalacion.
    DB::table('absences')->insert([
        'uuid' => $absenceUuid,
        'employee_id' => \is_numeric($employeeId) ? (int) $employeeId : 0,
        'type' => 'sick_leave',
        'starts_on' => '2026-06-02',
        'ends_on' => '2026-06-06',
        'note' => 'Parte de baja',
        'status' => 'active',
        'version' => 1,
        'created_at' => '2026-06-01T08:00:00+00:00',
    ]);

    return [
        'api/v1/employees/{uuid}' => '/api/v1/employees/'.$employeeUuid,
        'api/v1/employees/{uuid}/workdays' => '/api/v1/employees/'.$employeeUuid.'/workdays?from=2026-06-01&to=2026-06-07',
        'api/v1/absences' => '/api/v1/absences?from=2026-06-01&to=2026-06-30',
        'api/v1/absences/{uuid}' => '/api/v1/absences/'.$absenceUuid,
        'api/v1/compliance/summary' => '/api/v1/compliance/summary?from=2026-06-01&to=2026-06-07',
        // El descargable no existe y no hace falta que exista: el controlador
        // autoriza contra la CLASE antes de resolver nada, asi que un `403` aqui
        // es de `DataExportPolicy` y de nadie mas.
        'api/v1/data-export/{uuid}/download' => '/api/v1/data-export/'.Str::uuid7()->toString().'/download',
    ];
}

/**
 * De las rutas de lectura que el ambito alcanza, las que la aplicacion NO cierra.
 *
 * @param  array<string, string>  $paths
 * @return list<string>
 */
function supportReadRoutesLeftOpenFor(SupportScope $scope, string $token, array $paths): array
{
    $abiertas = [];

    foreach (supportReadRoutesReachableBy($scope) as $route) {
        $status = Api::as($token)->get($paths[$route->uri()] ?? '/'.$route->uri())->status();

        if ($status !== 403) {
            $abiertas[] = 'GET /'.$route->uri();
        }
    }

    $abiertas = array_values(array_unique($abiertas));
    sort($abiertas);

    return $abiertas;
}

/**
 * Las rutas de lectura con parametros que un alcance alcanza y para las que
 * nadie ha escrito un camino concreto.
 *
 * @return list<string>
 */
function supportReadRoutesWithoutPath(SupportScope $scope): array
{
    $sinCamino = [];
    $paths = supportReadPaths();

    foreach (supportReadRoutesReachableBy($scope) as $route) {
        if (str_contains($route->uri(), '{') && ! \array_key_exists($route->uri(), $paths)) {
            $sinCamino[] = $route->uri();
        }
    }

    return $sinCamino;
}

it('encuentra rutas de lectura que analizar', function (): void {
    // El control que impide que la comparacion de abajo pase por vacio, igual
    // que el de la mitad de escritura.
    expect(supportReadRoutes())->not->toBe([]);
})->group('RF-PD-11');

it('tiene un camino concreto para cada ruta de lectura parametrizada que un alcance alcanza', function (SupportScope $scope): void {
    // SIN ESTE CONTROL LA PRUEBA DE ABAJO SE DEGRADA EN SILENCIO. Una ruta nueva
    // con `{uuid}` que nadie anada a `supportReadPaths()` se pediria con la
    // llave literal, responderia `404` o `400`, y se contaria como abierta por
    // un motivo que no tiene nada que ver con la autorizacion.

    // arrange / act
    $sinCamino = supportReadRoutesWithoutPath($scope);

    // assert
    expect($sinCamino)->toBe([], 'Ruta(s) de lectura con parametros que el alcance '.$scope->value
        .' alcanza y que no tienen camino concreto en supportReadPaths(): '.implode(', ', $sinCamino));
})->with(SupportScope::cases())->group('RF-PD-11');

it('el fabricante solo lee lo que la lista cerrada le concede', function (SupportScope $scope): void {
    /*
     * LA LISTA CERRADA DE LECTURA. Cada linea lleva escrito por que el
     * fabricante puede leer eso, igual que la de escritura. Lo que NO aparece
     * aqui, o lo cierra una policy —y entonces no llega a compararse— o pone
     * esta prueba en rojo.
     */
    $permitidas = [
        /*
         * EL HISTORICO DE ERRORES (RF-PD-15). Lo alcanzan los tres alcances por
         * `diagnostics:*`, y es literalmente para lo que existe un acceso de
         * soporte. **No lleva dato personal y eso no se confia**: la regla dura
         * 21 prohibe nombres en `error_events` y lo comprueba
         * `ErrorEventsHaveNoPersonalDataTest`.
         */
        'GET /api/v1/diagnostics/errors',
        /*
         * LA CONFIGURACION DE LA INSTALACION y los quioscos (`settings:*`, solo
         * `configuration`). Es la incidencia para la que ese alcance existe: un
         * umbral operativo mal puesto, una zona horaria equivocada, un quiosco
         * que no vincula. Ninguno de los dos recursos describe a una persona.
         */
        'GET /api/v1/settings',
        'GET /api/v1/devices',
        /*
         * EL PERFIL DE CUMPLIMIENTO en LECTURA, y solo en lectura: es lo que
         * hace falta para diagnosticar por que salta una incidencia. Escribirlo
         * lo cierra `ComplianceProfilePolicy::update()`, porque ahi viven los
         * umbrales legales y `retention_years` (RL-01, RL-02, regla dura 14).
         * Decision de `seguridad-cumplimiento` en la revision de la tarea 5.9.
         */
        'GET /api/v1/compliance-profile',
        /*
         * LAS CINCO DE `read_only`, Y TODAS LLEVAN DATO PERSONAL. No es un
         * descuido: es el alcance entero. `read_only` existe para la incidencia
         * que el paquete anonimizado no resuelve —«a esta persona le salen ocho
         * horas y deberian ser nueve»—, y sin la plantilla, la presencia y las
         * jornadas no se puede mirar.
         *
         * Lo que las hace aceptables es lo que las rodea, no que sean inocuas:
         * el acceso es **temporal, concedido por el cliente y auditado** (RL-18,
         * ADR-020), y la lectura del registro de una persona deja su asiento con
         * el actor de soporte delante (RS-05), asi que el cliente puede
         * reconstruir despues que se miro y cuando.
         *
         * **Y llegan hasta aqui y no mas.** Ninguna sirve dato del art. 9 —eso
         * son las ausencias, y las cierra `AbsencePolicy`—, ninguna permite
         * corregir una hora (`attendance:correct` no lo concede ningun alcance)
         * y ninguna emite la exportacion para la Inspeccion (`reports:legal`
         * tampoco).
         */
        'GET /api/v1/employees',
        'GET /api/v1/employees/{uuid}',
        'GET /api/v1/employees/{uuid}/workdays',
        'GET /api/v1/attendance/live',
        'GET /api/v1/compliance/summary',
    ];

    // arrange
    $token = SupportGrants::tokenFor($scope);
    $paths = supportReadPaths();

    // act
    $abiertas = supportReadRoutesLeftOpenFor($scope, $token, $paths);

    // assert
    expect(array_values(array_diff($abiertas, $permitidas)))->toBe(
        [],
        'El alcance '.$scope->value.' LEE ruta(s) que nadie ha concedido por escrito, y ninguna policy lo para: '
        .implode(', ', array_diff($abiertas, $permitidas)),
    );
})->with(SupportScope::cases())->group('RF-PD-11', 'RL-19', 'ADR-020');

it('no deja al fabricante leer las ausencias, que es dato del art. 9', function (SupportScope $scope): void {
    /*
     * La cara concreta de lo de arriba, escrita aparte para que se lea sola.
     *
     * Las dos rutas de ausencias NO estan en la lista de permitidas, y con
     * `read_only` el AMBITO las alcanza: `employees:read` abre las dos. Lo unico
     * que las deja fuera de la comparacion es que responden `403`, y eso lo pone
     * `AbsencePolicy`.
     *
     * Si esa policy volviera a abrirse, las dos apareceran en `$abiertas`, no
     * estaran en `$permitidas` y la prueba de arriba se pondra roja. Esta lo
     * dice con el nombre delante.
     */

    // arrange
    $paths = supportReadPaths();

    // act
    $abiertas = supportReadRoutesLeftOpenFor($scope, SupportGrants::tokenFor($scope), $paths);

    // assert
    expect($abiertas)->not->toContain('GET /api/v1/absences')
        ->and($abiertas)->not->toContain('GET /api/v1/absences/{uuid}');
})->with(SupportScope::cases())->group('RF-PD-11', 'RL-19', 'RF-GP-04', 'ADR-020');
