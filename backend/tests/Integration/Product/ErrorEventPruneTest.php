<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\PruneErrorEvents;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\ErrorHistoryConnection;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La purga del ciclo corto: 90 dias, por `last_seen_at`, **y nada mas**
 * (RF-PD-15, RL-11, decision 10 de la ficha 5.12).
 *
 * ## Lo que de verdad se comprueba aqui
 *
 * Que esta purga **no es la del registro legal**. `compliance:apply-retention`
 * mueve jornadas y asientos a los cuatro anos del perfil de cumplimiento; esta
 * borra diagnostico tecnico. Confundirlas seria borrar un registro con valor
 * probatorio por una configuracion pensada para un log.
 *
 * El reloj va fijo (regla dura 2): una prueba de retencion que llamara a `now()`
 * pasaria trescientos sesenta y cuatro dias al ano y fallaria uno.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Clock::class, FixedClock::at('2027-06-01 04:00:00'));
    ErrorHistoryConnection::shareTestTransaction();
});

// Ver `ErrorEventClockAndCapTest`: en `Integration` el puente se deshace a mano,
// porque el enganche global de `tests/Pest.php` solo cubre `Feature`.
afterEach(function (): void {
    ErrorHistoryConnection::release();
});

/** Un grupo visto por ultima vez en la fecha indicada. */
function grupoVistoEl(string $lastSeenAt): void
{
    DB::table('error_events')->insert([
        'fingerprint' => bin2hex(random_bytes(32)),
        'level' => 'error',
        'source' => 'api',
        'message' => 'algo fallo el '.$lastSeenAt,
        'context' => '{}',
        'app_version' => '2.2.0',
        'occurrences' => 1,
        // Primera aparicion muy antigua a proposito: lo que vence es un grupo
        // MUERTO, no uno que empezo hace tiempo y sigue vivo.
        'first_seen_at' => '2024-01-01 00:00:00+00',
        'last_seen_at' => $lastSeenAt,
    ]);
}

it('borra por la ULTIMA vez que se vio, no por la primera', function (): void {
    Config::set('compliance.retention.error_history_days', 90);

    // 90 dias antes del 1 de junio de 2027 es el 3 de marzo.
    grupoVistoEl('2027-01-15 10:00:00+00');
    grupoVistoEl('2027-05-31 10:00:00+00');

    $purga = app(PruneErrorEvents::class);

    expect($purga->pending())->toBe(1)
        ->and($purga->handle())->toBe(1)
        ->and(DB::table('error_events')->count())->toBe(1)
        // El que sigue vivo se queda, aunque su primera aparicion sea de 2024.
        ->and(DB::table('error_events')->value('last_seen_at'))->toContain('2027-05-31');
})->group('RF-PD-15', 'RL-11');

it('respeta el plazo configurado en ERROR_HISTORY_RETENTION_DAYS', function (): void {
    // La MISMA variable que ya leia el ciclo corto de retencion: dos plazos para
    // la misma tabla serian dos purgas que se contradicen.
    Config::set('compliance.retention.error_history_days', 30);

    grupoVistoEl('2027-04-15 10:00:00+00');

    expect(app(PruneErrorEvents::class)->retentionDays())->toBe(30)
        ->and(app(PruneErrorEvents::class)->handle())->toBe(1);

    Config::set('compliance.retention.error_history_days', 365);

    grupoVistoEl('2027-04-15 10:00:00+00');

    expect(app(PruneErrorEvents::class)->handle())->toBe(0)
        ->and(DB::table('error_events')->count())->toBe(1);
})->group('RF-PD-15', 'RL-11');

it('la simulacion no toca ni una fila', function (): void {
    Config::set('compliance.retention.error_history_days', 90);

    grupoVistoEl('2027-01-15 10:00:00+00');

    expect(app(PruneErrorEvents::class)->pending())->toBe(1)
        ->and(DB::table('error_events')->count())->toBe(1);
})->group('RF-PD-15', 'RL-11');

it('no toca audit_log ni el registro de jornada', function (): void {
    // Es la afirmacion central de esta prueba y la razon de que exista: la purga
    // del historico tecnico y la del registro legal son dos cosas distintas, con
    // dos plazos distintos y dos lectores distintos (regla dura 6).
    Config::set('compliance.retention.error_history_days', 90);

    $escenario = AttendanceFixtures::scenario();
    $siteId = WorkforceFixtures::onlySiteId();
    $employeeId = AttendanceFixtures::employeeIdOf($escenario['employee']);

    DB::table('shift_entries')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employeeId,
        'site_id' => $siteId,
        // Muy anterior al corte del historico de errores: si la purga se
        // confundiera de tabla, esta jornada seria lo primero en irse.
        'work_date' => '2024-01-15',
        'clocked_in_at' => '2024-01-15 06:00:00+00',
        'clocked_out_at' => '2024-01-15 14:00:00+00',
        'duration_minutes' => 480,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => '2024-01-15 06:00:00+00',
        'updated_at' => '2024-01-15 14:00:00+00',
    ]);

    $asientos = DB::table('audit_log')->count();
    $jornadas = DB::table('shift_entries')->count();

    grupoVistoEl('2027-01-15 10:00:00+00');

    expect(app(PruneErrorEvents::class)->handle())->toBe(1)
        ->and(DB::table('audit_log')->count())->toBe($asientos)
        ->and(DB::table('shift_entries')->count())->toBe($jornadas);
})->group('RF-PD-15', 'RL-11');

it('purga por lotes y se lleva todo lo vencido, aunque haya mas que el tamano del lote', function (): void {
    /*
     * El bucle es lo que impide que la purga de las 03:35 sea un `DELETE` largo
     * que retiene bloqueos sobre la misma tabla en la que se esta escribiendo
     * cada error que ocurra mientras corre —y a esa hora corren las tareas
     * nocturnas, que son las que mas errores producen—.
     *
     * Lo que esta prueba fija es lo que el bucle podria romper: que con mas
     * filas vencidas que el tamano del lote **no se quede ninguna dentro**.
     */
    Config::set('compliance.retention.error_history_days', 90);
    Config::set('compliance.retention.batch_size', 3);

    foreach (range(1, 10) as $indice) {
        grupoVistoEl('2027-01-'.str_pad((string) $indice, 2, '0', STR_PAD_LEFT).' 10:00:00+00');
    }

    // Y una vigente, que no se puede llevar por delante.
    grupoVistoEl('2027-05-31 10:00:00+00');

    $purga = app(PruneErrorEvents::class);

    expect($purga->pending())->toBe(10)
        ->and($purga->handle())->toBe(10)
        ->and(DB::table('error_events')->count())->toBe(1);
})->group('RF-PD-15', 'RL-11');

it('la simulacion y el borrado cuentan exactamente lo mismo', function (): void {
    // El filtro de la consulta es inclusivo y el borrado usa `<`; sin el ajuste
    // de un microsegundo, un grupo justo en el corte saldria en una cifra y no
    // en la otra. Un ensayo que dice una cosa y una ejecucion que hace otra es
    // peor que no tener ensayo.
    Config::set('compliance.retention.error_history_days', 90);

    $corte = app(PruneErrorEvents::class)->cutoff();

    // Uno justo en el corte -que NO vence, porque el borrado es estricto- y uno
    // un microsegundo antes.
    grupoVistoEl($corte->format('Y-m-d H:i:s.u').'+00');
    grupoVistoEl($corte->modify('-1 microsecond')->format('Y-m-d H:i:s.u').'+00');

    $purga = app(PruneErrorEvents::class);
    $anunciados = $purga->pending();

    expect($anunciados)->toBe(1)
        ->and($purga->handle())->toBe($anunciados);
})->group('RF-PD-15', 'RL-11');

it('purgar no deja asiento en audit_log', function (): void {
    // Al contrario que `compliance:apply-retention`: esto no es evidencia legal,
    // y un asiento por cada madrugada llenaria de ruido tecnico la cadena que se
    // conserva cuatro anos.
    Config::set('compliance.retention.error_history_days', 90);

    grupoVistoEl('2027-01-15 10:00:00+00');

    $antes = DB::table('audit_log')->count();

    app(PruneErrorEvents::class)->handle();

    expect(DB::table('audit_log')->count())->toBe($antes);
})->group('RF-PD-15', 'RL-11');
