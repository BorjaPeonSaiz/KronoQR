<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Cambio de turno real** — escenario ineludible del doc 02 §9.4: *«30
 * empleados distintos fichando simultaneamente en el mismo quiosco -> un tramo
 * por persona, sin duplicados y con `daily_totals` cuadrando con los eventos
 * origen»*.
 *
 * *«Es el pico que ocurre a diario, no un caso de laboratorio.»* A las siete de
 * la manana el turno de noche sale y el de manana entra, y las dos plantillas se
 * cruzan delante de la misma tablet. Si el sistema se rompe alguna vez, se rompe
 * aqui.
 *
 * Lo que se pone a prueba no es el rendimiento —eso es k6 y RNF-P-06— sino la
 * **correccion bajo contencion**: treinta transacciones concurrentes que
 * escriben en `shift_entries`, recalculan `daily_totals` y se serializan una
 * detras de otra en el candado de la cadena de `audit_log` (ADR-027). Un fallo
 * aqui no es lento: es una jornada que falta.
 */

uses(CommittedDatabase::class);

const EMPLEADOS_EN_EL_CAMBIO = 30;

it('registra un tramo por persona sin duplicados ni jornadas perdidas', function (): void {
    $site = WorkforceFixtures::site('Hotel en cambio de turno');
    $department = WorkforceFixtures::department($site);
    $device = AttendanceFixtures::device($site, 'Entrada de personal');
    $token = AttendanceFixtures::tokenFor($device['id']);

    // Treinta personas y treinta tarjetas: el mismo quiosco, credenciales
    // distintas.
    $credenciales = FakeCredentialResolver::new();
    $tarjetas = [];

    for ($i = 0; $i < EMPLEADOS_EN_EL_CAMBIO; $i++) {
        $tarjeta = 'FH1.a3.tarjeta'.str_pad((string) $i, 15, '0', STR_PAD_LEFT).'.firma'.$i;
        $tarjetas[] = $tarjeta;
        $credenciales->resolving($tarjeta, WorkforceFixtures::employee($site, $department));
    }

    app()->instance(Clock::class, FixedClock::at('2026-03-14 06:00:00'));
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    app()->instance(CredentialResolver::class, $credenciales);

    $respuestas = ParallelRequests::run(
        EMPLEADOS_EN_EL_CAMBIO,
        static function (int $indice) use ($token, $tarjetas): mixed {
            $scanId = Str::uuid7()->toString();

            return Api::as($token)
                ->withHeaders(['Idempotency-Key' => $scanId])
                ->post('/api/v1/scan', [
                    'scan_id' => $scanId,
                    'occurred_at' => '2026-03-14T06:00:00Z',
                    'qr_payload' => $tarjetas[$indice],
                ]);
        },
    );

    // --- Nadie se queda sin fichar ------------------------------------------

    $codigos = array_map(static fn (array $r): int => $r['status'], $respuestas);
    $acciones = array_map(
        static function (array $r): string {
            $accion = is_array($r['body']) ? ($r['body']['action'] ?? null) : null;

            // Una respuesta sin `action` no es «otra accion»: es una respuesta
            // rota, y tiene que distinguirse en la asercion en lugar de
            // colapsarse a `null` junto con las demas.
            return is_string($accion) ? $accion : 'sin-accion';
        },
        $respuestas,
    );

    expect(array_unique($codigos))->toBe([200])
        // Las treinta abren turno: son personas distintas, asi que ninguna cae
        // en el anti-rebote de otra (RF-AT-06 es por empleado, no por quiosco).
        ->and(array_values(array_unique($acciones)))->toBe(['clock_in']);

    // --- Un tramo por persona, sin duplicados -------------------------------

    expect(DB::table('shift_entries')->count())->toBe(EMPLEADOS_EN_EL_CAMBIO)
        ->and(DB::table('shift_entries')->distinct()->count('employee_id'))->toBe(EMPLEADOS_EN_EL_CAMBIO)
        ->and(DB::table('scan_events')->count())->toBe(EMPLEADOS_EN_EL_CAMBIO)
        ->and(DB::table('scan_events')->where('result', 'clock_in')->count())->toBe(EMPLEADOS_EN_EL_CAMBIO);

    // --- `daily_totals` cuadra con los eventos origen ------------------------

    // Las dos consultas de la verificacion de RN-06: la proyeccion y la suma de
    // los tramos vigentes tienen que devolver exactamente lo mismo (regla dura
    // 7, ADR-007). Es lo que la reconciliacion nocturna de RF-PR-02 comprobara
    // cada noche en produccion.
    expect(DB::table('daily_totals')->count())->toBe(EMPLEADOS_EN_EL_CAMBIO)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([])
        // Todas las jornadas abiertas y en cero: un tramo abierto aporta cero al
        // total legal, y el panel en vivo sabe que estan dentro.
        ->and(DB::table('daily_totals')->where('has_open_shift', true)->count())->toBe(EMPLEADOS_EN_EL_CAMBIO)
        ->and(DB::table('daily_totals')->sum('total_minutes'))->toBe(0);

    // --- Y cada fichaje dejo su asiento de auditoria -------------------------

    // RL-01 y regla dura 6: treinta fichajes, treinta asientos, encadenados sin
    // bifurcarse pese a que las treinta transacciones fueron concurrentes. Es lo
    // que el candado consultivo de `DatabaseAuditTrail` garantiza y lo que
    // `compliance:verify-audit-chain` denunciaria si fallara.
    $asientos = DB::table('audit_log')
        ->where('action', 'shift_entry.created')
        ->orderBy('id')
        ->get();

    expect($asientos->count())->toBeGreaterThanOrEqual(EMPLEADOS_EN_EL_CAMBIO)
        ->and($asientos->pluck('hash')->unique()->count())->toBe($asientos->count())
        ->and($asientos->pluck('prev_hash')->unique()->count())->toBe($asientos->count());
})->group('RQ-03', 'RN-06', 'RNF-P-01');
