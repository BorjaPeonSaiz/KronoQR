<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\EmployeePins;

/*
 * **La huella que deja un fichaje por PIN**: origen, marca de revision y
 * auditoria (RF-AT-11, doc 02 §7.5, tarea 1.12).
 *
 * Es la segunda mitad del Gherkin del doc 01 §11 —*«Entonces se registra el
 * fichaje con origen "PIN" Y queda marcado para revision del responsable»*— y el
 * fichero que la verificacion del plan busca por nombre
 * (`--filter=PinScanOrigin`).
 *
 * **Por que estas tres cosas van juntas.** Son lo unico que distingue un fichaje
 * por PIN de uno por tarjeta, porque la respuesta HTTP es identica a proposito.
 * Si alguna de las tres se perdiera, el fichaje seguiria funcionando y nadie se
 * enteraria hasta que la bandeja de la tarea 2.5 —que se construye sobre
 * `flagged_for_review`— naciera vacia de historico.
 */

uses(RefreshDatabase::class);

const PIN_ORIGEN = '730415';

/**
 * @return array{site: int, employee: string, code: string, publicKey: string, device: int, deviceUuid: string, token: string}
 */
function escenarioDeOrigen(string $ahora = '2026-03-14 07:02:31'): array
{
    $escenario = AttendanceFixtures::scenario();

    EmployeePins::issue($escenario['employee'], PIN_ORIGEN);

    app()->instance(Clock::class, FixedClock::at($ahora));
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);

    return [
        ...$escenario,
        'code' => EmployeePins::codeOf($escenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
    ];
}

/**
 * @param  array{token: string, code: string, publicKey: string, ...}  $escenario
 * @return TestResponse<Response>
 */
function ficharPorPin(array $escenario, string $scanId, string $occurredAt = '2026-03-14T07:02:31Z'): TestResponse
{
    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'employee_code' => $escenario['code'],
            'pin_sealed' => EmployeePins::seal(PIN_ORIGEN, $escenario['publicKey']),
        ]);
}

it('registra el escaneo con origen pin_kiosk', function (): void {
    $escenario = escenarioDeOrigen();
    $scanId = Str::uuid7()->toString();

    ficharPorPin($escenario, $scanId)->assertOk();

    expect(DB::table('scan_events')->where('scan_id', $scanId)->value('origin'))->toBe('pin_kiosk');
})->group('RF-AT-11');

it('marca el fichaje por PIN para revision del responsable', function (): void {
    // §7.5: «en el quiosco, el fichaje por PIN queda marcado para revision del
    // responsable, lo que hace visible cualquier uso anomalo». La bandeja que lo
    // trabaja es la tarea 2.5; lo exigible hoy es que la marca este, porque sin
    // ella esa bandeja nacera sin historico que mostrar.
    $escenario = escenarioDeOrigen();
    $scanId = Str::uuid7()->toString();

    ficharPorPin($escenario, $scanId)->assertOk();

    expect(DB::table('scan_events')->where('scan_id', $scanId)->value('flagged_for_review'))->toBeTrue();
})->group('RF-AT-11');

it('marcar no es rechazar', function (): void {
    // Regla dura 19. La marca es para el responsable, no para el empleado: el
    // fichaje se registra, crea su tramo y devuelve 200.
    $escenario = escenarioDeOrigen();

    ficharPorPin($escenario, Str::uuid7()->toString())->assertOk();

    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('daily_totals')->value('total_minutes'))->toBe(0);
})->group('RF-AT-11', 'RN-15');

it('no marca el escaneo de tarjeta, para que la bandeja no se llene de ruido', function (): void {
    // La marca solo tiene valor si distingue. Si todo escaneo la llevara, la
    // bandeja de la 2.5 seria el log de fichajes y nadie la miraria.
    $escenario = escenarioDeOrigen();
    $scanId = Str::uuid7()->toString();

    ficharPorPin($escenario, $scanId)->assertOk();

    expect(DB::table('scan_events')->where('scan_id', $scanId)->value('origin'))->toBe('pin_kiosk');

    // Y el tramo lleva el origen en la marca, no en la jornada: se entra con la
    // tarjeta y se sale con el PIN, o al reves (docblock de `ScanOrigin`).
    expect(DB::table('shift_entries')->value('clock_in_source'))->toBe('pin_kiosk');
})->group('RF-AT-11');

it('marca tambien el fichaje por PIN suprimido por el anti-rebote', function (): void {
    // El anti-rebote es un desenlace ACEPTADO (ADR-031), asi que un PIN dentro de
    // la ventana de gracia sigue siendo un uso del PIN. Dejarlo sin marca
    // esconderia justo el patron que hace visible el uso anomalo: teclear el PIN
    // de otro dos veces seguidas.
    $escenario = escenarioDeOrigen();

    ficharPorPin($escenario, Str::uuid7()->toString(), '2026-03-14T07:02:31Z')->assertOk();

    $segundo = Str::uuid7()->toString();
    $respuesta = ficharPorPin($escenario, $segundo, '2026-03-14T07:02:51Z');

    $respuesta->assertOk();

    expect($respuesta->json('action'))->toBe('debounced');

    $evento = DB::table('scan_events')->where('scan_id', $segundo)->first();

    expect($evento?->result)->toBe('rejected_debounce')
        ->and($evento?->flagged_for_review)->toBeTrue()
        ->and($evento?->origin)->toBe('pin_kiosk');
})->group('RF-AT-11', 'RF-AT-06');

it('no marca un intento rechazado, que no es un fichaje', function (): void {
    // `flagged_for_review` alimenta una bandeja de FICHAJES que revisar. Un
    // intento que no produjo tramo no es un fichaje: su rastro esta en
    // `scan_events.result` y en la metrica.
    $escenario = escenarioDeOrigen();
    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'employee_code' => $escenario['code'],
            'pin_sealed' => EmployeePins::seal('000000', $escenario['publicKey']),
        ])
        ->assertStatus(422);

    expect(DB::table('scan_events')->where('scan_id', $scanId)->value('flagged_for_review'))->toBeFalse();
})->group('RF-AT-11');

it('deja el fichaje por PIN en audit_log con su origen y sin nombres', function (): void {
    // Regla dura 6 y RL-01: el fichaje por PIN tiene la misma relevancia legal
    // que el de la tarjeta. Lo escribe el mismo listener de `Compliance`, dentro
    // de la misma transaccion, porque el caso de uso es el mismo — que es
    // exactamente el motivo por el que esta via reutiliza `RegisterScanHandler`
    // en vez de tener un camino propio.
    $escenario = escenarioDeOrigen();

    ficharPorPin($escenario, Str::uuid7()->toString(), '2026-03-14T07:02:31Z')->assertOk();

    app()->instance(Clock::class, FixedClock::at('2026-03-14 15:02:31'));
    ficharPorPin($escenario, Str::uuid7()->toString(), '2026-03-14T15:02:31Z')->assertOk();

    /** @var list<stdClass> $asientos */
    $asientos = DB::table('audit_log')->orderBy('id')->get()->all();

    expect($asientos)->toHaveCount(2);

    [$entrada, $salida] = $asientos;

    expect($entrada->action)->toBe('shift_entry.created')
        ->and($salida->action)->toBe('shift_entry.closed')
        // El actor sigue siendo el quiosco: el PIN identifica a la persona, no
        // convierte al empleado en el actor de la escritura.
        ->and($entrada->actor_type)->toBe('device')
        ->and($entrada->actor_id)->toBe($escenario['device'])
        // Encadenado (RS-07, ADR-032).
        ->and($salida->prev_hash)->toBe($entrada->hash);

    foreach ($asientos as $asiento) {
        expect($asiento->payload)
            // Con su origen, que es lo que permite a una inspeccion distinguir
            // meses despues un fichaje con tarjeta de uno con PIN.
            ->toContain('pin_kiosk')
            ->and($asiento->payload)->toContain($escenario['employee'])
            // Regla dura 21: ni un nombre, ni el PIN, ni el codigo de empleado.
            ->and($asiento->payload)->not->toContain('Persona')
            ->and($asiento->payload)->not->toContain('De Prueba')
            ->and($asiento->payload)->not->toContain(PIN_ORIGEN)
            ->and($asiento->payload)->not->toContain($escenario['code']);
    }
})->group('RF-AT-11', 'RL-01', 'RS-07');
