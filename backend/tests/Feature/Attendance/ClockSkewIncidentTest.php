<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;

/*
 * **«Reloj del quiosco desviado» de punta a punta** (doc 01 §11, RF-AT-10,
 * RN-15, escenario ineludible del doc 02 §9.4).
 *
 *     Escenario: Reloj del quiosco desviado
 *       Dado un quiosco con el reloj 40 minutos adelantado
 *       Cuando un empleado ficha
 *       Entonces el fichaje se registra con el occurred_at del dispositivo
 *       Y se crea una incidencia de tipo "clock_skew"
 *       Y en ningun caso se rechaza el fichaje
 *
 * ## Por que hacia falta esta prueba, si las dos mitades ya existian
 *
 * Porque estaban **cortadas por la mitad**. `RegisterScanTest` comprueba que el
 * escaneo adelantado se acepta y queda marcado; `IncidentDetectionTest`
 * comprueba que un `scan_events` marcado —escrito a mano— produce la incidencia.
 * Nadie recorria el camino entero, y entre las dos mitades esta justo lo que
 * puede romperse sin que ninguna se entere: que la columna que el fichaje marca
 * sea la que la pasada nocturna lee, que el tramo al que apunta sea el mismo, y
 * que el umbral con el que se marca sea el que se publica en el contexto de la
 * incidencia.
 *
 * **El fichaje nunca se rechaza** (regla dura 19): el desfase es un problema
 * tecnico ajeno al empleado, y lo que se abre es una revision, no un error.
 *
 * El reloj esta detenido (regla dura 2, ADR-021): el desfase es precisamente la
 * diferencia entre dos instantes, y con un reloj real no habria nada que medir.
 */

uses(RefreshDatabase::class);

const TARJETA_DESFASE = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

/**
 * Quiosco emparejado, empleado con responsable —para que la incidencia tenga a
 * quien asignarse— y credencial resuelta por el doble.
 *
 * @return array{site: int, employee: string, token: string, manager: int}
 */
function escenarioDeDesfase(string $ahora): array
{
    $escenario = AttendanceFixtures::scenario();

    $manager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    Department::query()->whereKey($escenario['department'])->update(['manager_user_id' => $manager->id]);

    FrozenTime::at($ahora);

    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(TARJETA_DESFASE, $escenario['employee']),
    );

    Spectator::using('openapi.yaml');

    return [...$escenario, 'manager' => $manager->id];
}

/**
 * @param  array{token: string, ...}  $escenario
 */
function ficharConDesfase(array $escenario, string $occurredAt): string
{
    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'qr_payload' => TARJETA_DESFASE,
        ])
        // **200 y no un error**: es la primera linea del Gherkin y la mas
        // importante de todo el fichero.
        ->assertOk()
        ->assertValidRequest()
        ->assertValidResponse();

    return $scanId;
}

it('registra el fichaje del quiosco desviado y abre la incidencia de la noche', function (): void {
    Notification::fake();

    // El servidor cree que son las 07:00; la tablet dice que son las 07:40.
    $escenario = escenarioDeDesfase('2026-03-14 07:00:00');

    $entrada = ficharConDesfase($escenario, '2026-03-14T07:40:00Z');

    // Y la salida, con el mismo adelanto: el tramo entero viene de un reloj que
    // va mal, que es como ocurre de verdad.
    FrozenTime::at('2026-03-14 15:00:00');
    ficharConDesfase($escenario, '2026-03-14T15:40:00Z');

    $escaneo = DB::table('scan_events')->where('scan_id', $entrada)->first();
    $tramo = DB::table('shift_entries')->first();

    expect(DB::table('shift_entries')->count())->toBe(1)
        // El registro legal usa el `occurred_at` del dispositivo (regla dura 9,
        // RN-15): no se «corrige» a la hora del servidor, porque eso seria
        // inventar una hora de entrada que nadie ficho.
        ->and(substr((string) $tramo?->clocked_in_at, 0, 19))->toBe('2026-03-14 07:40:00')
        ->and(substr((string) $escaneo?->occurred_at, 0, 19))->toBe('2026-03-14 07:40:00')
        // Cuarenta minutos de ADELANTO: el signo negativo es lo que distingue
        // una tablet adelantada de una cola offline retrasada.
        ->and($escaneo?->clock_skew_seconds)->toBe(-2400)
        ->and($escaneo?->result)->toBe('clock_in')
        ->and($escaneo?->flagged_for_review)->toBeTrue();

    // --- La pasada nocturna, que es la otra mitad del Gherkin -----------------

    FrozenTime::at('2026-03-15 03:00:00');

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);

    $incidencia = DB::table('incidents')->where('type', 'clock_skew')->first();

    expect($incidencia)->not->toBeNull()
        ->and($incidencia?->severity)->toBe('low')
        ->and($incidencia?->work_date)->toBe('2026-03-14')
        // Asignada al responsable del departamento: una incidencia sin dueño es
        // una incidencia que nadie mira (RF-PR-01).
        ->and($incidencia?->assigned_to_user_id)->toBe($escenario['manager'])
        // Y apunta al tramo, para que el panel pueda llevar a quien la revise
        // hasta la jornada concreta.
        ->and($incidencia?->shift_entry_id)->toBe($tramo?->id);

    /** @var array<string, int> $contexto */
    $contexto = json_decode((string) $incidencia?->context, true, 512, JSON_THROW_ON_ERROR);

    // El desfase medido **con su signo** y el umbral con el que se juzgo. Lo
    // segundo importa tanto como lo primero: sin el umbral, quien lee la
    // incidencia no puede saber si 2400 segundos es mucho en esta instalacion
    // (regla dura 14). Y el signo negativo dice «la tablet va adelantada», que
    // es lo que hay que ir a corregir.
    expect($contexto)->toEqualCanonicalizing([
        'clock_skew_seconds' => -2400,
        'threshold_seconds' => 900,
    ]);

    // Y la jornada no se perdio por el camino: el resultado esperado de la ficha
    // dice «ninguna jornada queda sin registrar».
    expect(DB::table('daily_totals')->where('work_date', '2026-03-14')->value('total_minutes'))->toBe(480);
})->group('RF-AT-10', 'RN-15', 'RF-PR-01');

it('no marca ni abre nada por un desfase que se queda en el umbral', function (): void {
    // El otro lado del umbral de 15 minutos que el §9.5 exige probar: aqui son
    // 14 min 59 s —899 segundos— y no se marca nada; los 40 minutos de la prueba
    // anterior si. Si la marca saltara con cualquier desfase, toda la plantilla
    // apareceria marcada y la bandeja dejaria de distinguir nada, que es la forma
    // practica de que una incidencia deje de existir.
    //
    // El limite exacto —900 s justos— lo fija `ReviewPolicyTest` sin base de
    // datos, que es donde se puede afirmar «estrictamente mayor» sin montar dos
    // escenarios enteros.
    Notification::fake();

    $escenario = escenarioDeDesfase('2026-03-14 07:00:00');

    $scanId = ficharConDesfase($escenario, '2026-03-14T07:14:59Z');

    $escaneo = DB::table('scan_events')->where('scan_id', $scanId)->first();

    expect($escaneo?->clock_skew_seconds)->toBe(-899)
        ->and($escaneo?->flagged_for_review)->toBeFalse();

    FrozenTime::at('2026-03-15 03:00:00');
    Artisan::call('attendance:detect-incidents');

    expect(DB::table('incidents')->where('type', 'clock_skew')->count())->toBe(0);
})->group('RF-AT-10', 'RN-15');
