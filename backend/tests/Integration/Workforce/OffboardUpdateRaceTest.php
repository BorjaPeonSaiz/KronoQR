<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Identity\Application\Command\RotateSigningKeyCommand;
use App\Modules\Identity\Application\UseCase\RotateSigningKey;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\UseCase\PlanEmployeeImport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Concurrency\ChildSessions;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **TREINTA CARRERAS DE VERDAD CONTRA UNA BAJA, Y NINGUNA DEJA LA BASE
 * CONTRADICIENDO AL REGISTRO** (ADR-046 §6 punto 1; RN-14, RL-04; hallazgos
 * R7-RV-01 y R4-BE-01, y A-1 y A-2 del dictamen de seguridad).
 *
 * Es la prueba que reprodujo el fallo: por HTTP, dos procesos a la vez, 15 de
 * cada 30 dejaban a la persona `active` con un `employee.offboarded` en el
 * registro. Aqui cada tanda son 30 rondas, cada ronda con su persona y sus
 * peticiones lanzadas a la vez en procesos distintos (`ParallelRequests`): la
 * base decide quien gana y la prueba no elige el orden.
 *
 * **El criterio se lee de la base y no de las respuestas**, y es el mismo en
 * todas las tandas:
 *
 * - ningun `employee.offboarded` de una persona que no este `terminated`;
 * - ninguna persona `terminated` con una tarjeta sin revocar;
 * - ninguna persona `terminated` sin su asiento, ni con dos;
 * - **cero `40P01`**: ni en una respuesta ni en el contador de abrazos mortales
 *   de PostgreSQL (`pg_stat_database.deadlocks`), que tambien cuenta los que un
 *   caso de uso hubiera reintentado en silencio. **Excepto en la tanda del alta
 *   contra una modificacion con el mismo correo**, que es el caso conocido de
 *   ADR-046 §1.3: ahi los abrazos mortales estan permitidos —el caso de uso los
 *   reintenta— y lo que se exige es que ninguno llegue al cliente (ningun 5xx) y
 *   que el correo acabe en una sola persona.
 *
 * Las tandas cubren cada camino que ADR-046 §1.2 hace pasar por el orden unico
 * (filas padre → cadena → ficha → tarjetas): modificacion, importacion, PIN
 * (restablecimiento, que emite, y entrega), emision de tarjeta y rotacion de la
 * clave contra una baja; el renombrado de un departamento contra un cambio de
 * departamento; y el renombrado del centro contra un fichaje y un alta, que es
 * la que demuestra §1.3 (filas padre antes que la cadena).
 *
 * `CommittedDatabase`: los procesos hijos no ven una transaccion sin confirmar.
 */

uses(CommittedDatabase::class);

const OFFBOARD_UPDATE_RACE_ROUNDS = 30;

const OFFBOARD_UPDATE_RACE_NOW = '2026-10-02 10:00:00';

const OFFBOARD_UPDATE_RACE_LAST_DAY = '2026-10-02';

const OFFBOARD_UPDATE_RACE_CARD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

beforeEach(function (): void {
    FrozenTime::at(OFFBOARD_UPDATE_RACE_NOW);
});

/**
 * Lanza las tareas a la vez, una por proceso, y devuelve lo que paso en cada
 * una como texto: `http:<codigo>` o, para un caso de uso sin endpoint,
 * `committed` / `sqlstate:<codigo>` / `rejected:<excepcion>`.
 *
 * @param  list<callable(): string>  $tareas
 * @return list<string>
 */
function aLaVezContraLaBaja(array $tareas): array
{
    /** @var list<string> $desenlaces */
    $desenlaces = ParallelRequests::runTasks(\count($tareas), static fn (int $indice): string => $tareas[$indice]());

    return $desenlaces;
}

/**
 * @param  TestResponse<Response>  $respuesta
 */
function desenlaceHttpDeLaCarrera(TestResponse $respuesta): string
{
    $codigo = $respuesta->getStatusCode();

    // Un 5xx lleva el cuerpo: es donde aparece un `40P01` o un `55P03`.
    return $codigo >= 500
        ? 'http:'.$codigo.':'.mb_substr((string) $respuesta->getContent(), 0, 400)
        : 'http:'.$codigo;
}

function bajaEnLaCarrera(string $token, string $persona): callable
{
    return static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->post(
        '/api/v1/employees/'.$persona.'/offboard',
        ['terminated_at' => OFFBOARD_UPDATE_RACE_LAST_DAY, 'reason' => 'Fin de contrato'],
    ));
}

/**
 * Una persona en alta, con correo, PIN emitido y una tarjeta impresa sin
 * revocar.
 */
function personaParaLaCarrera(int $ronda, ?int $departamento = null, bool $conTarjeta = true): string
{
    $site = WorkforceFixtures::onlySiteId();
    $uuid = WorkforceFixtures::employee(
        $site,
        $departamento,
        lastName: 'Apellido Original',
    );
    DB::table('employees')->where('uuid', $uuid)->update(['email' => 'carrera.'.$ronda.'@example.test']);
    EmployeePins::issue($uuid, '374195');

    if ($conTarjeta) {
        Credentials::issueFor(AttendanceFixtures::employeeIdOf($uuid));
    }

    return $uuid;
}

function tokenDeLaCarrera(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

/**
 * Las contradicciones entre `audit_log`, `employees` y `credentials` de ADR-046
 * §6, para todas las personas. Vacia es coherente.
 *
 * @return list<string>
 */
function contradiccionesTrasLaCarrera(): array
{
    /** @var list<object{contradiccion: string}> $rows */
    $rows = DB::select(<<<'SQL'
        SELECT 'employee.offboarded con la persona ' || e.uuid::text || ' en ' || e.status AS contradiccion
          FROM audit_log a
          JOIN employees e ON e.uuid::text = a.payload->>'employee_uuid'
         WHERE a.action = 'employee.offboarded'
           AND (e.status <> 'terminated' OR e.terminated_at IS NULL)
        UNION ALL
        SELECT 'persona terminated ' || e.uuid::text || ' con la tarjeta ' || c.uuid::text || ' sin revocar'
          FROM credentials c
          JOIN employees e ON e.id = c.employee_id
         WHERE e.status = 'terminated'
           AND c.revoked_at IS NULL
        UNION ALL
        SELECT 'persona terminated ' || e.uuid::text || ' sin asiento employee.offboarded'
          FROM employees e
         WHERE e.status = 'terminated'
           AND NOT EXISTS (
                 SELECT 1 FROM audit_log a
                  WHERE a.action = 'employee.offboarded' AND a.payload->>'employee_uuid' = e.uuid::text
               )
        UNION ALL
        SELECT 'dos employee.offboarded de ' || (a.payload->>'employee_uuid')
          FROM audit_log a
         WHERE a.action = 'employee.offboarded'
         GROUP BY a.payload->>'employee_uuid'
        HAVING count(*) > 1
    SQL);

    return array_map(static fn (object $row): string => $row->contradiccion, $rows);
}

/**
 * Los `employee.updated` que dicen que cambio `last_name` cuando la base tiene
 * otro apellido que el pedido.
 *
 * @return list<string>
 */
function apellidosQueElRegistroContradice(string $pedido): array
{
    /** @var list<object{uuid: string}> $rows */
    $rows = DB::select(<<<'SQL'
        SELECT e.uuid::text AS uuid
          FROM audit_log a
          JOIN employees e ON e.uuid::text = a.payload->>'employee_uuid'
         WHERE a.action = 'employee.updated'
           AND a.payload->'changed_fields' @> '["last_name"]'::jsonb
           AND e.last_name <> ?
    SQL, [$pedido]);

    return array_map(static fn (object $row): string => $row->uuid, $rows);
}

/**
 * Los abrazos mortales que PostgreSQL ha detectado en esta base desde que
 * arranco. Se compara antes y despues de la tanda.
 */
function abrazosMortalesDetectados(): int
{
    esperarAQueSeCierrenLosHijos();
    DB::select('SELECT pg_stat_clear_snapshot()');

    /** @var object{deadlocks: int} $row */
    $row = DB::selectOne('SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()');

    return (int) $row->deadlocks;
}

/**
 * Espera, con tope, a que no quede en esta base ninguna sesion de los procesos
 * hijos.
 *
 * Un backend vuelca sus estadisticas —tambien los abrazos mortales que vio— al
 * cerrar, y el padre llega aqui justo despues de `pcntl_waitpid`: el proceso
 * hijo ha terminado, pero su sesion de PostgreSQL puede seguir cerrandose. Leer
 * el contador en ese hueco daria un falso «cero 40P01» (revision del bloque 17).
 * El tope evita colgar la suite si alguna sesion ajena se queda abierta: en ese
 * caso se lee lo que haya.
 */
function esperarAQueSeCierrenLosHijos(): void
{
    ChildSessions::waitUntilGone();
}

/**
 * Los desenlaces que no pueden darse en ninguna tanda: un 5xx o un corte de la
 * base.
 *
 * @param  list<list<string>>  $rondas
 * @return list<string>
 */
function desenlacesImposibles(array $rondas): array
{
    return array_values(array_filter(
        array_merge(...$rondas),
        static fn (string $desenlace): bool => str_starts_with($desenlace, 'http:5') || str_starts_with($desenlace, 'sqlstate:'),
    ));
}

/**
 * Cuantas veces aparece cada desenlace en la posicion indicada de cada ronda.
 *
 * @param  list<list<string>>  $rondas
 * @return array<string, int>
 */
function desenlacesEnLaPosicion(array $rondas, int $posicion): array
{
    $cuenta = array_count_values(array_column($rondas, $posicion));
    ksort($cuenta);

    return $cuenta;
}

/**
 * Corre las rondas. Cada ronda prepara su escenario en el proceso de la prueba
 * y despues lanza sus tareas a la vez.
 *
 * @param  callable(int): list<callable(): string>  $ronda
 * @return array{rondas: list<list<string>>, abrazos: int}
 */
function tandaContraLaBaja(callable $ronda): array
{
    $antes = abrazosMortalesDetectados();
    $rondas = [];

    for ($indice = 0; $indice < OFFBOARD_UPDATE_RACE_ROUNDS; $indice++) {
        $rondas[] = aLaVezContraLaBaja($ronda($indice));
    }

    return ['rondas' => $rondas, 'abrazos' => abrazosMortalesDetectados() - $antes];
}

it('treinta modificaciones a la vez que una baja: ninguna reactiva a nadie ni deja un asiento falso', function (): void {
    WorkforceFixtures::site('Hotel de la carrera');
    $token = tokenDeLaCarrera();

    $tanda = tandaContraLaBaja(static function (int $ronda) use ($token): array {
        $persona = personaParaLaCarrera($ronda);

        return [
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->patch(
                '/api/v1/employees/'.$persona,
                ['last_name' => 'Apellido Carrera'],
            )),
            bajaEnLaCarrera($token, $persona),
        ];
    });

    expect(desenlacesImposibles($tanda['rondas']))->toBe([])
        ->and($tanda['abrazos'])->toBe(0)
        ->and(contradiccionesTrasLaCarrera())->toBe([])
        ->and(apellidosQueElRegistroContradice('Apellido Carrera'))->toBe([])
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 1))->toBe(['http:200' => OFFBOARD_UPDATE_RACE_ROUNDS])
        ->and(DB::table('employees')->where('status', 'terminated')->count())->toBe(OFFBOARD_UPDATE_RACE_ROUNDS);
})->group('RN-14', 'RF-GP-01', 'RF-GP-03', 'RL-04');

it('treinta importaciones a la vez que una baja: ninguna reactiva a nadie', function (): void {
    WorkforceFixtures::site('Hotel de la carrera');
    $token = tokenDeLaCarrera();
    /** @var array<string, list<string>> $aliases */
    $aliases = config()->array('workforce.import.column_aliases');

    $tanda = tandaContraLaBaja(static function (int $ronda) use ($token, $aliases): array {
        $persona = personaParaLaCarrera($ronda);
        $fichero = ImportFiles::csv(ImportFiles::rows(
            ['nombre', 'apellidos', 'email', 'fecha_alta'],
            [['Persona', 'Apellido Carrera', 'carrera.'.$ronda.'@example.test', '2026-01-01']],
        ));
        $huella = app(PlanEmployeeImport::class)->handle((string) $fichero->getRealPath(), 500, $aliases)->sha256;

        return [
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->upload(
                '/api/v1/employees/import',
                ['mode' => 'apply', 'confirm_checksum' => $huella],
                ['file' => $fichero],
            )),
            bajaEnLaCarrera($token, $persona),
        ];
    });

    expect(desenlacesImposibles($tanda['rondas']))->toBe([])
        ->and($tanda['abrazos'])->toBe(0)
        ->and(contradiccionesTrasLaCarrera())->toBe([])
        ->and(apellidosQueElRegistroContradice('Apellido Carrera'))->toBe([])
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 1))->toBe(['http:200' => OFFBOARD_UPDATE_RACE_ROUNDS])
        ->and(DB::table('employees')->where('status', 'terminated')->count())->toBe(OFFBOARD_UPDATE_RACE_ROUNDS);
})->group('RN-14', 'RF-GP-05', 'RF-GP-03', 'RL-04');

it('treinta restablecimientos de PIN a la vez que una baja: sin abrazo mortal y sin contradicciones', function (): void {
    WorkforceFixtures::site('Hotel de la carrera');
    $token = tokenDeLaCarrera();

    $tanda = tandaContraLaBaja(static function (int $ronda) use ($token): array {
        $persona = personaParaLaCarrera($ronda);

        return [
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->post('/api/v1/employees/'.$persona.'/pin/reset')),
            bajaEnLaCarrera($token, $persona),
        ];
    });

    expect(desenlacesImposibles($tanda['rondas']))->toBe([])
        ->and($tanda['abrazos'])->toBe(0)
        ->and(contradiccionesTrasLaCarrera())->toBe([])
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 1))->toBe(['http:200' => OFFBOARD_UPDATE_RACE_ROUNDS]);
})->group('RN-14', 'RF-ID-09', 'RF-GP-03', 'RL-04');

it('treinta entregas de PIN a la vez que una baja: sin abrazo mortal y sin contradicciones', function (): void {
    WorkforceFixtures::site('Hotel de la carrera');
    $token = tokenDeLaCarrera();

    $tanda = tandaContraLaBaja(static function (int $ronda) use ($token): array {
        $persona = personaParaLaCarrera($ronda);

        return [
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->post('/api/v1/employees/'.$persona.'/pin/deliver')),
            bajaEnLaCarrera($token, $persona),
        ];
    });

    expect(desenlacesImposibles($tanda['rondas']))->toBe([])
        ->and($tanda['abrazos'])->toBe(0)
        ->and(contradiccionesTrasLaCarrera())->toBe([])
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 1))->toBe(['http:200' => OFFBOARD_UPDATE_RACE_ROUNDS]);
})->group('RN-14', 'RF-ID-09', 'RF-GP-03', 'RL-04');

it('treinta emisiones de tarjeta a la vez que una baja: nadie de baja se queda con una tarjeta activa', function (): void {
    WorkforceFixtures::site('Hotel de la carrera');
    $token = tokenDeLaCarrera();

    $tanda = tandaContraLaBaja(static function (int $ronda) use ($token): array {
        $persona = personaParaLaCarrera($ronda, conTarjeta: false);

        return [
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->post('/api/v1/credentials', ['employee_uuid' => $persona])),
            bajaEnLaCarrera($token, $persona),
        ];
    });

    expect(desenlacesImposibles($tanda['rondas']))->toBe([])
        ->and($tanda['abrazos'])->toBe(0)
        ->and(contradiccionesTrasLaCarrera())->toBe([])
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 1))->toBe(['http:200' => OFFBOARD_UPDATE_RACE_ROUNDS]);
})->group('RN-14', 'RF-QR-03', 'RF-GP-03', 'RL-04');

it('treinta rotaciones de la clave a la vez que una baja: la rotacion no reemite tarjeta a quien se da de baja', function (): void {
    WorkforceFixtures::site('Hotel de la carrera');
    $token = tokenDeLaCarrera();

    $tanda = tandaContraLaBaja(static function (int $ronda) use ($token): array {
        $persona = personaParaLaCarrera($ronda, conTarjeta: false);
        Credentials::issueFor(AttendanceFixtures::employeeIdOf($persona), Credentials::previousKey());

        return [
            // La rotacion no tiene endpoint: es un comando de consola. Se llama
            // al caso de uso, en su proceso, como lo haria el comando.
            static function (): string {
                try {
                    app(RotateSigningKey::class)->handle(new RotateSigningKeyCommand);

                    return 'committed';
                } catch (QueryException $failure) {
                    return 'sqlstate:'.(string) $failure->getCode();
                }
            },
            bajaEnLaCarrera($token, $persona),
        ];
    });

    expect(desenlacesImposibles($tanda['rondas']))->toBe([])
        ->and($tanda['abrazos'])->toBe(0)
        ->and(contradiccionesTrasLaCarrera())->toBe([])
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 0))->toBe(['committed' => OFFBOARD_UPDATE_RACE_ROUNDS])
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 1))->toBe(['http:200' => OFFBOARD_UPDATE_RACE_ROUNDS]);
})->group('RN-14', 'RF-QR-07', 'RF-GP-03', 'RL-04');

it('treinta renombrados de departamento a la vez que alguien se cambia a ese departamento: sin abrazo mortal', function (): void {
    $site = WorkforceFixtures::site('Hotel de la carrera');
    $token = tokenDeLaCarrera();

    $tanda = tandaContraLaBaja(static function (int $ronda) use ($token, $site): array {
        $persona = personaParaLaCarrera($ronda, WorkforceFixtures::department($site, 'Pisos'));
        $cocina = WorkforceFixtures::department($site, 'Cocina');

        return [
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->patch(
                '/api/v1/departments/'.$cocina,
                ['name' => 'Cocina ronda '.$ronda],
            )),
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->patch(
                '/api/v1/employees/'.$persona,
                ['department_id' => $cocina],
            )),
        ];
    });

    expect(desenlacesImposibles($tanda['rondas']))->toBe([])
        ->and($tanda['abrazos'])->toBe(0)
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 0))->toBe(['http:200' => OFFBOARD_UPDATE_RACE_ROUNDS])
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 1))->toBe(['http:200' => OFFBOARD_UPDATE_RACE_ROUNDS])
        ->and(DB::table('departments')->where('name', 'like', 'Cocina ronda %')->count())->toBe(OFFBOARD_UPDATE_RACE_ROUNDS);
})->group('RF-GP-01', 'RL-04');

it('treinta renombrados del centro a la vez que un fichaje y un alta: el fichaje nunca es la victima', function (): void {
    $site = WorkforceFixtures::site('Hotel de la carrera');
    $token = tokenDeLaCarrera();
    $quiosco = AttendanceFixtures::tokenFor(AttendanceFixtures::device($site)['id']);
    $departamento = WorkforceFixtures::department($site, 'Recepcion');
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);

    $tanda = tandaContraLaBaja(static function (int $ronda) use ($token, $quiosco, $departamento): array {
        $persona = personaParaLaCarrera($ronda);
        app()->instance(
            CredentialResolver::class,
            FakeCredentialResolver::new()->resolving(OFFBOARD_UPDATE_RACE_CARD, $persona),
        );
        $escaneo = Str::uuid7()->toString();

        return [
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->patch(
                '/api/v1/site',
                ['name' => 'Hotel de la carrera, ronda '.$ronda],
            )),
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($quiosco)
                ->withHeaders(['Idempotency-Key' => $escaneo])
                ->post('/api/v1/scan', [
                    'scan_id' => $escaneo,
                    'occurred_at' => '2026-10-02T09:59:00Z',
                    'qr_payload' => OFFBOARD_UPDATE_RACE_CARD,
                ])),
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->post('/api/v1/employees', [
                'first_name' => 'Alta',
                'last_name' => 'Ronda '.$ronda,
                'department_id' => $departamento,
                'hired_at' => '2026-10-02',
            ])),
        ];
    });

    expect(desenlacesImposibles($tanda['rondas']))->toBe([])
        ->and($tanda['abrazos'])->toBe(0)
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 0))->toBe(['http:200' => OFFBOARD_UPDATE_RACE_ROUNDS])
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 1))->toBe(['http:200' => OFFBOARD_UPDATE_RACE_ROUNDS])
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 2))->toBe(['http:201' => OFFBOARD_UPDATE_RACE_ROUNDS])
        ->and(DB::table('shift_entries')->count())->toBe(OFFBOARD_UPDATE_RACE_ROUNDS)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-AT-07', 'RN-15', 'RF-GP-01', 'RL-04');

it('treinta altas de tramo a la vez que una baja con cese anterior: ningun tramo queda escrito despues de la baja', function (): void {
    // Revision del bloque 17: el alta manual lee la ficha sin candado y la
    // vuelve a mirar con la cadena tomada, despues de su asiento. O el tramo
    // entra antes que la baja en la cadena, o la ve y responde 422 sin escribir.
    WorkforceFixtures::site('Hotel de la carrera');
    $token = tokenDeLaCarrera();

    $tanda = tandaContraLaBaja(static function (int $ronda) use ($token): array {
        $persona = personaParaLaCarrera($ronda);

        return [
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->post('/api/v1/shift-entries', [
                'employee_uuid' => $persona,
                'work_date' => OFFBOARD_UPDATE_RACE_LAST_DAY,
                'clocked_in_at' => OFFBOARD_UPDATE_RACE_LAST_DAY.'T06:00:00Z',
                'clocked_out_at' => OFFBOARD_UPDATE_RACE_LAST_DAY.'T09:00:00Z',
                'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
            ])),
            // Cese el dia anterior a la jornada del tramo.
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->post(
                '/api/v1/employees/'.$persona.'/offboard',
                ['terminated_at' => '2026-10-01'],
            )),
        ];
    });

    /** @var list<object{uuid: string}> $tramosTrasLaBaja */
    $tramosTrasLaBaja = DB::select(<<<'SQL'
        SELECT b.payload->>'employee_uuid' AS uuid
          FROM audit_log b
          JOIN audit_log t ON t.payload->>'employee_uuid' = b.payload->>'employee_uuid'
         WHERE b.action = 'employee.offboarded'
           AND t.action = 'shift_entry.created'
           AND t.id > b.id
    SQL);

    expect(desenlacesImposibles($tanda['rondas']))->toBe([])
        ->and($tanda['abrazos'])->toBe(0)
        ->and(contradiccionesTrasLaCarrera())->toBe([])
        ->and($tramosTrasLaBaja)->toBe([])
        ->and(array_diff(array_keys(desenlacesEnLaPosicion($tanda['rondas'], 0)), ['http:201', 'http:422']))->toBe([])
        ->and(desenlacesEnLaPosicion($tanda['rondas'], 1))->toBe(['http:200' => OFFBOARD_UPDATE_RACE_ROUNDS]);
})->group('RN-14', 'RF-PA-04', 'RL-04');

it('treinta altas a la vez que dos modificaciones que escriben el mismo correo: nunca un 5xx y el correo es de una sola persona', function (): void {
    // ADR-046 §1.3, caso conocido: el alta inserta el correo antes de la
    // cadena y la espera; la modificacion tiene la cadena y espera al indice
    // unico. Si PostgreSQL rompe el ciclo, el caso de uso reintenta una vez y
    // responde el 409 del correo duplicado. Los abrazos mortales no se cuentan
    // aqui: son el caso conocido, y lo que importa es que no lleguen al cliente.
    // Tres escritores y no dos: con tres, un reintento puede volver a cruzarse,
    // y ese segundo cruce tiene que salir como 409 (`ConcurrentEmployeeWrite`),
    // no como 500 (revision del bloque 17, segunda pasada).
    WorkforceFixtures::site('Hotel de la carrera');
    $token = tokenDeLaCarrera();

    $tanda = tandaContraLaBaja(static function (int $ronda) use ($token): array {
        $persona = personaParaLaCarrera($ronda);
        $otra = personaParaLaCarrera($ronda + 1000);
        $correo = 'compartido.'.$ronda.'@example.test';

        return [
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->post('/api/v1/employees', [
                'first_name' => 'Alta',
                'last_name' => 'Ronda '.$ronda,
                'email' => $correo,
                'hired_at' => '2026-10-02',
            ])),
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->patch(
                '/api/v1/employees/'.$persona,
                ['email' => $correo],
            )),
            static fn (): string => desenlaceHttpDeLaCarrera(Api::as($token)->patch(
                '/api/v1/employees/'.$otra,
                ['email' => $correo],
            )),
        ];
    });

    $exitosPorRonda = array_map(
        static fn (array $ronda): int => \count(array_filter($ronda, static fn (string $d): bool => \in_array($d, ['http:200', 'http:201'], true))),
        $tanda['rondas'],
    );

    expect(desenlacesImposibles($tanda['rondas']))->toBe([])
        ->and(array_diff(array_merge(...$tanda['rondas']), ['http:200', 'http:201', 'http:409']))->toBe([])
        ->and(array_unique($exitosPorRonda))->toBe([1])
        ->and(DB::table('employees')->where('email', 'like', 'compartido.%')->count())->toBe(OFFBOARD_UPDATE_RACE_ROUNDS);
})->group('RF-GP-01', 'RL-04');
