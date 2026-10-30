<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El fichaje por PIN que llega despues de la baja** (RN-20, D15, enmienda del
 * 29-10-2026 de ADR-043; ADR-062 §8; tarea 25.5 de la 2.3.0), de punta a punta.
 *
 * El Gherkin del doc 01: «Nadia» ficha por PIN su salida a las 15:00 de su
 * ultimo dia en una tablet sin red; RRHH registra su baja a las 16:00 con cese
 * ese dia; la tablet sincroniza a las 17:00. Dos mitades:
 *
 *   - **La respuesta no cambia** (RS-03, regla dura 17): el PIN correcto dentro
 *     del periodo, el PIN correcto despues del cese y el PIN incorrecto dan el
 *     mismo cuerpo, byte a byte, con las mismas consultas, tambien al
 *     reenviarlos.
 *   - **La fila y la revision diaria si**: solo el primero queda atribuido y
 *     marcado, y abre `scan_before_revocation`; con tarjeta y PIN el mismo dia,
 *     una sola.
 *
 * El centro es Madrid (UTC+2 en agosto). Cese el 15 de agosto de 2026.
 */

uses(RefreshDatabase::class);

const PIN_AFTER_OFFBOARDING_GOOD = '481902';

const PIN_AFTER_OFFBOARDING_BAD = '481903';

/** La sincronizacion de las 17:00 en Madrid. */
const PIN_AFTER_OFFBOARDING_NOW = '2026-08-15 15:00:00';

/** La salida de las 15:00 en Madrid del dia del cese. */
const PIN_AFTER_OFFBOARDING_LAST_DAY = '2026-08-15T13:00:00Z';

/** Las 09:00 en Madrid del dia siguiente al cese. */
const PIN_AFTER_OFFBOARDING_NEXT_DAY = '2026-08-16T07:00:00Z';

/**
 * El quiosco, un departamento con responsable y el sellado del PIN.
 *
 * @return array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string, publicKey: string}
 */
function pinTrasLaBajaEscenario(): array
{
    $scenario = AttendanceFixtures::scenario();
    $manager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    Department::query()->whereKey($scenario['department'])->update(['manager_user_id' => $manager->id]);

    FrozenTime::at(PIN_AFTER_OFFBOARDING_NOW);

    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    app(Cache::class)->clear();
    app()->forgetInstance(PinAttempts::class);

    Spectator::using('openapi.yaml');

    return [...$scenario, 'publicKey' => EmployeePins::configureSealing()];
}

/**
 * Una persona de baja con PIN: alta el 1 de marzo y cese el 15 de agosto.
 *
 * @param  array{site: int, department: int, ...}  $scenario
 * @return array{uuid: string, id: int, code: string}
 */
function personaDeBajaConPin(array $scenario, string $status = 'terminated'): array
{
    $uuid = WorkforceFixtures::employee(
        $scenario['site'],
        $scenario['department'],
        $status,
        hiredAt: '2026-03-01',
        terminatedAt: '2026-08-15',
    );

    EmployeePins::issue($uuid, PIN_AFTER_OFFBOARDING_GOOD);

    return ['uuid' => $uuid, 'id' => AttendanceFixtures::employeeIdOf($uuid), 'code' => EmployeePins::codeOf($uuid)];
}

/**
 * @param  array{token: string, publicKey: string, ...}  $scenario
 * @return TestResponse<Response>
 */
function pinTrasLaBaja(array $scenario, string $code, string $pin, string $occurredAt, string $scanId): TestResponse
{
    return Api::as($scenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'employee_code' => $code,
            'pin_sealed' => EmployeePins::seal($pin, $scenario['publicKey']),
        ]);
}

/**
 * La peticion, su cuerpo sin el eco del `scan_id` y cuantas consultas hizo.
 *
 * @param  array{token: string, publicKey: string, ...}  $scenario
 * @return array{0: TestResponse<Response>, 1: string, 2: int}
 */
function pinTrasLaBajaMedido(array $scenario, string $code, string $pin, string $occurredAt, string $scanId): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = pinTrasLaBaja($scenario, $code, $pin, $occurredAt, $scanId);

    $queries = \count(DB::getRawQueryLog());
    DB::disableQueryLog();

    return [$response, str_replace($scanId, '<scan_id>', (string) $response->getContent()), $queries];
}

/**
 * @return object{employee_id: int|null, claimed_employee_id: int|null, flagged_for_review: bool, result: string, origin: string}
 */
function filaDelPinTrasLaBaja(string $scanId): object
{
    /** @var object{employee_id: int|null, claimed_employee_id: int|null, flagged_for_review: bool, result: string, origin: string} $row */
    $row = DB::table('scan_events')
        ->where('scan_id', $scanId)
        ->first(['employee_id', 'claimed_employee_id', 'flagged_for_review', 'result', 'origin']);

    return $row;
}

function revisionDiariaTrasLaBaja(string $now = '2026-08-16 03:30:00'): void
{
    Notification::fake();
    FrozenTime::at($now);

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);
}

it('rechaza igual el PIN correcto dentro y fuera del periodo y el incorrecto, y solo atribuye y marca el primero', function (): void {
    $scenario = pinTrasLaBajaEscenario();

    // Tres personas de baja, un intento cada una: con una sola, los tres
    // intentos abririan el bloqueo de RS-12 y el tercero cambiaria de camino.
    $dentro = personaDeBajaConPin($scenario);
    $despues = personaDeBajaConPin($scenario);
    $incorrecto = personaDeBajaConPin($scenario);

    // Calentamiento: la primera peticion paga cachés de arranque.
    pinTrasLaBaja($scenario, 'ENOEXISTE', PIN_AFTER_OFFBOARDING_BAD, PIN_AFTER_OFFBOARDING_LAST_DAY, Str::uuid7()->toString());

    $scanDentro = Str::uuid7()->toString();
    $scanDespues = Str::uuid7()->toString();
    $scanIncorrecto = Str::uuid7()->toString();

    [$respuestaDentro, $cuerpoDentro, $consultasDentro] = pinTrasLaBajaMedido($scenario, $dentro['code'], PIN_AFTER_OFFBOARDING_GOOD, PIN_AFTER_OFFBOARDING_LAST_DAY, $scanDentro);
    [$respuestaDespues, $cuerpoDespues, $consultasDespues] = pinTrasLaBajaMedido($scenario, $despues['code'], PIN_AFTER_OFFBOARDING_GOOD, PIN_AFTER_OFFBOARDING_NEXT_DAY, $scanDespues);
    [$respuestaIncorrecto, $cuerpoIncorrecto, $consultasIncorrecto] = pinTrasLaBajaMedido($scenario, $incorrecto['code'], PIN_AFTER_OFFBOARDING_BAD, PIN_AFTER_OFFBOARDING_LAST_DAY, $scanIncorrecto);

    $respuestaDentro->assertStatus(422)->assertValidRequest()->assertValidResponse(422);
    $respuestaDespues->assertStatus(422)->assertValidResponse(422);
    $respuestaIncorrecto->assertStatus(422)->assertValidResponse(422);

    // Byte a byte salvo el eco del `scan_id`, y el mismo trabajo (RS-03).
    expect($cuerpoDentro)->toBe($cuerpoDespues)->toBe($cuerpoIncorrecto)
        ->and($cuerpoDentro)->not->toContain($dentro['uuid'])
        ->and([$consultasDentro, $consultasDespues])->toBe([$consultasIncorrecto, $consultasIncorrecto]);

    $filaDentro = filaDelPinTrasLaBaja($scanDentro);
    $filaDespues = filaDelPinTrasLaBaja($scanDespues);
    $filaIncorrecto = filaDelPinTrasLaBaja($scanIncorrecto);

    expect($filaDentro->result)->toBe('rejected_unknown')
        ->and($filaDentro->origin)->toBe('pin_kiosk')
        ->and($filaDentro->employee_id)->toBe($dentro['id'])
        ->and($filaDentro->flagged_for_review)->toBeTrue()
        // No es un `PinClaim`: el PIN acerto (el CHECK de ADR-043 no cambia).
        ->and($filaDentro->claimed_employee_id)->toBeNull()
        // Despues del cese: sin persona y sin marca, como un PIN incorrecto.
        ->and($filaDespues->result)->toBe('rejected_unknown')
        ->and($filaDespues->employee_id)->toBeNull()
        ->and($filaDespues->flagged_for_review)->toBeFalse()
        ->and($filaDespues->claimed_employee_id)->toBeNull()
        // PIN incorrecto de una persona de baja: nada (RN-19 no la cubre).
        ->and($filaIncorrecto->result)->toBe('rejected_unknown')
        ->and($filaIncorrecto->employee_id)->toBeNull()
        ->and($filaIncorrecto->flagged_for_review)->toBeFalse()
        ->and($filaIncorrecto->claimed_employee_id)->toBeNull()
        // Ningun rechazo crea ni rectifica un tramo.
        ->and(DB::table('shift_entries')->count())->toBe(0);
})->group('RN-20', 'RN-14', 'RS-03', 'RF-AT-11');

it('devuelve el mismo cuerpo al reenviar el PIN atribuido y el que no se atribuyo, sin duplicar la fila', function (): void {
    // Regla dura 8 y RS-03: el reenvio de la cola offline responde lo mismo, y
    // la fila atribuida no cambia ni la forma ni el trabajo del reenvio.
    $scenario = pinTrasLaBajaEscenario();
    $dentro = personaDeBajaConPin($scenario);
    $despues = personaDeBajaConPin($scenario);

    $atribuido = Str::uuid7()->toString();
    $sinPersona = Str::uuid7()->toString();

    $primeraAtribuida = pinTrasLaBaja($scenario, $dentro['code'], PIN_AFTER_OFFBOARDING_GOOD, PIN_AFTER_OFFBOARDING_LAST_DAY, $atribuido);
    $primeraSinPersona = pinTrasLaBaja($scenario, $despues['code'], PIN_AFTER_OFFBOARDING_GOOD, PIN_AFTER_OFFBOARDING_NEXT_DAY, $sinPersona);

    [$reenvioAtribuido, $cuerpoAtribuido, $consultasAtribuido] = pinTrasLaBajaMedido($scenario, $dentro['code'], PIN_AFTER_OFFBOARDING_GOOD, PIN_AFTER_OFFBOARDING_LAST_DAY, $atribuido);
    [$reenvioSinPersona, $cuerpoSinPersona, $consultasSinPersona] = pinTrasLaBajaMedido($scenario, $despues['code'], PIN_AFTER_OFFBOARDING_GOOD, PIN_AFTER_OFFBOARDING_NEXT_DAY, $sinPersona);

    $reenvioAtribuido->assertStatus(422)->assertValidResponse(422);
    $reenvioSinPersona->assertStatus(422)->assertValidResponse(422);

    expect($reenvioAtribuido->getContent())->toBe($primeraAtribuida->getContent())
        ->and($reenvioSinPersona->getContent())->toBe($primeraSinPersona->getContent())
        ->and($cuerpoAtribuido)->toBe($cuerpoSinPersona)
        ->and($consultasAtribuido)->toBe($consultasSinPersona)
        ->and(DB::table('scan_events')->whereIn('scan_id', [$atribuido, $sinPersona])->count())->toBe(2)
        ->and(filaDelPinTrasLaBaja($atribuido)->employee_id)->toBe($dentro['id']);
})->group('RN-20', 'RF-AT-07', 'RS-03');

it('no atribuye un PIN correcto anterior al alta ni el de una persona suspendida', function (): void {
    // El `occurred_at` lo pone la tablet: sin cota inferior, quien conozca el
    // PIN podria sembrar una incidencia por cada fecha que eligiera. Y la
    // suspension no es una baja (RN-25).
    $scenario = pinTrasLaBajaEscenario();
    $deBaja = personaDeBajaConPin($scenario);
    $suspendida = personaDeBajaConPin($scenario, 'suspended');

    $antesDelAlta = Str::uuid7()->toString();
    $deSuspendida = Str::uuid7()->toString();

    // Las 23:59 del 28 de febrero en Madrid: la vispera del alta.
    pinTrasLaBaja($scenario, $deBaja['code'], PIN_AFTER_OFFBOARDING_GOOD, '2026-02-28T22:59:00Z', $antesDelAlta)->assertStatus(422);
    pinTrasLaBaja($scenario, $suspendida['code'], PIN_AFTER_OFFBOARDING_GOOD, PIN_AFTER_OFFBOARDING_LAST_DAY, $deSuspendida)->assertStatus(422);

    expect(filaDelPinTrasLaBaja($antesDelAlta)->employee_id)->toBeNull()
        ->and(filaDelPinTrasLaBaja($antesDelAlta)->flagged_for_review)->toBeFalse()
        ->and(filaDelPinTrasLaBaja($deSuspendida)->employee_id)->toBeNull()
        ->and(filaDelPinTrasLaBaja($deSuspendida)->flagged_for_review)->toBeFalse();
})->group('RN-20', 'RN-25', 'RS-03');

it('abre una sola scan_before_revocation para un dia con tarjeta y con PIN, y nada para el PIN posterior al cese', function (): void {
    // Enmienda de ADR-043, punto 3: la revision diaria lee las filas marcadas
    // por `recorded_at` sin mirar el origen y agrupa por persona y jornada.
    $scenario = pinTrasLaBajaEscenario();
    $nadia = personaDeBajaConPin($scenario);
    $otra = personaDeBajaConPin($scenario);

    // Su tarjeta, revocada por la baja el 15 a las 06:00 UTC y usada a las 05:30.
    $tarjeta = Credentials::issueFor($nadia['id'], revokedReason: 'offboarding')->toString();
    $conTarjeta = Str::uuid7()->toString();

    Api::as($scenario['token'])
        ->withHeaders(['Idempotency-Key' => $conTarjeta])
        ->post('/api/v1/scan', ['scan_id' => $conTarjeta, 'occurred_at' => '2026-08-15T05:30:00Z', 'qr_payload' => $tarjeta])
        ->assertStatus(422);

    pinTrasLaBaja($scenario, $nadia['code'], PIN_AFTER_OFFBOARDING_GOOD, PIN_AFTER_OFFBOARDING_LAST_DAY, Str::uuid7()->toString())->assertStatus(422);
    pinTrasLaBaja($scenario, $otra['code'], PIN_AFTER_OFFBOARDING_GOOD, PIN_AFTER_OFFBOARDING_NEXT_DAY, Str::uuid7()->toString())->assertStatus(422);

    revisionDiariaTrasLaBaja();

    /** @var list<object{employee_id: int, work_date: string, context: string, shift_entry_id: int|null}> $incidencias */
    $incidencias = DB::table('incidents')->where('type', 'scan_before_revocation')->get()->all();

    expect($incidencias)->toHaveCount(1)
        ->and($incidencias[0]->employee_id)->toBe($nadia['id'])
        ->and(substr($incidencias[0]->work_date, 0, 10))->toBe('2026-08-15')
        ->and($incidencias[0]->shift_entry_id)->toBeNull();

    /** @var array{attempts: int, withdrawal: string, scan_id: string} $contexto */
    $contexto = json_decode($incidencias[0]->context, true, flags: JSON_THROW_ON_ERROR);

    expect($contexto['attempts'])->toBe(2)
        ->and($contexto['withdrawal'])->toBe('offboarding')
        ->and($contexto['scan_id'])->toBe($conTarjeta)
        ->and(json_encode($contexto, JSON_THROW_ON_ERROR))->not->toContain($nadia['code']);

    // La pasada siguiente no repite.
    revisionDiariaTrasLaBaja('2026-08-17 03:30:00');

    expect(DB::table('incidents')->where('type', 'scan_before_revocation')->count())->toBe(1);
})->group('RN-20', 'RF-PR-01', 'RS-03');
