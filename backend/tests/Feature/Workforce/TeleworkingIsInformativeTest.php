<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA MARCA DE TELETRABAJO NO CAMBIA EL FICHAJE NI NINGUN CALCULO** (RF-GP-01,
 * decision del propietario en `docs/verificacion/2.1.0-decisiones-comerciales.md`).
 *
 * Dos personas identicas salvo la marca —mismo centro, mismo departamento,
 * tarjeta real firmada, mismo quiosco— fichan exactamente a las mismas horas:
 * una jornada de dia, un turno de noche que cruza la medianoche (RN-05) y un
 * olvido de salida que la pasada nocturna convierte en incidencia. Todo lo que
 * el producto deriva de esos fichajes tiene que salir igual para las dos: la
 * respuesta del quiosco, el resultado de cada escaneo, los tramos, la
 * proyeccion de `daily_totals` (RN-06) y las incidencias.
 *
 * La guarda estatica que impide que alguien lea la marca desde el fichaje o los
 * informes es `Architecture/TeleworkingStaysInformativeTest`.
 */

uses(RefreshDatabase::class);

/**
 * Las horas de los fichajes, en UTC. El ultimo se queda sin salida.
 *
 * @var list<string>
 */
const TELEWORKING_IS_INFORMATIVE_SCANS = [
    '2026-03-14T07:00:00Z', '2026-03-14T15:00:00Z',
    '2026-03-15T22:00:00Z', '2026-03-16T06:00:00Z',
    '2026-03-17T07:00:00Z',
];

/**
 * Ficha con la tarjeta y devuelve lo que el quiosco recibe, sin lo que es
 * propio de cada escaneo (su `scan_id`) o de cada persona (su nombre visible).
 *
 * @return array<string, mixed>
 */
function teletrabajoInformativoFichar(string $token, string $payload, string $occurredAt): array
{
    FrozenTime::at(substr(str_replace('T', ' ', $occurredAt), 0, 19));

    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($token)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'qr_payload' => $payload,
        ])
        ->assertOk()
        ->assertValidRequest()
        ->assertValidResponse();

    /** @var array<string, mixed> $json */
    $json = $respuesta->json();

    return array_diff_key($json, array_flip(['scan_id', 'display_name', 'employee_display_name']));
}

/**
 * Lo que el producto ha derivado de los fichajes de una persona, sin claves
 * internas ni marcas de cuando se calculo.
 *
 * @return array{scans: list<string>, shifts: list<array<string, mixed>>, totals: list<array<string, mixed>>, incidents: list<array<string, mixed>>}
 */
function teletrabajoInformativoDerivado(int $employeeId): array
{
    $rows = static fn (string $sql): array => array_map(
        static fn (object $row): array => (array) $row,
        DB::select($sql, [$employeeId]),
    );

    /** @var list<string> $scans */
    $scans = DB::table('scan_events')->where('employee_id', $employeeId)->orderBy('occurred_at')->pluck('result')->all();

    /** @var list<array<string, mixed>> $shifts */
    $shifts = array_values($rows(<<<'SQL'
        SELECT work_date::text, clocked_in_at::text, clocked_out_at::text, status
          FROM shift_entries WHERE employee_id = ? ORDER BY clocked_in_at
    SQL));

    /** @var list<array<string, mixed>> $totals */
    $totals = array_values($rows(<<<'SQL'
        SELECT work_date::text, total_minutes, shift_count, first_in_at::text, last_out_at::text,
               has_open_shift, has_incident
          FROM daily_totals WHERE employee_id = ? ORDER BY work_date
    SQL));

    /** @var list<array<string, mixed>> $incidents */
    $incidents = array_values($rows(<<<'SQL'
        SELECT work_date::text, type, severity, status
          FROM incidents WHERE employee_id = ? ORDER BY work_date, type
    SQL));

    return ['scans' => $scans, 'shifts' => $shifts, 'totals' => $totals, 'incidents' => $incidents];
}

it('dos personas identicas salvo la marca producen el mismo fichaje, los mismos totales y las mismas incidencias', function (): void {
    Notification::fake();
    Spectator::using('openapi.yaml');

    $escenario = AttendanceFixtures::scenario();
    $presencial = $escenario['employee'];
    $remota = WorkforceFixtures::employee($escenario['site'], $escenario['department']);

    DB::table('employees')->where('uuid', $remota)->update(['teleworking' => true]);

    $idPresencial = AttendanceFixtures::employeeIdOf($presencial);
    $idRemota = AttendanceFixtures::employeeIdOf($remota);

    $tarjetaPresencial = Credentials::issueFor($idPresencial)->toString();
    $tarjetaRemota = Credentials::issueFor($idRemota)->toString();

    foreach (TELEWORKING_IS_INFORMATIVE_SCANS as $occurredAt) {
        // El quiosco responde lo mismo a las dos, escaneo a escaneo.
        expect(teletrabajoInformativoFichar($escenario['token'], $tarjetaRemota, $occurredAt))
            ->toEqual(teletrabajoInformativoFichar($escenario['token'], $tarjetaPresencial, $occurredAt));
    }

    // La pasada nocturna convierte el olvido de salida en incidencia.
    FrozenTime::at('2026-03-18 03:00:00');
    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);

    $derivadoPresencial = teletrabajoInformativoDerivado($idPresencial);
    $derivadoRemota = teletrabajoInformativoDerivado($idRemota);

    // Red de seguridad: lo que se compara no esta vacio.
    expect($derivadoPresencial['scans'])->toHaveCount(5)
        ->and($derivadoPresencial['shifts'])->toHaveCount(3)
        ->and($derivadoPresencial['totals'])->toHaveCount(3)
        ->and($derivadoPresencial['incidents'])->not->toBe([])
        // RN-05: el turno de noche es un tramo de la jornada en que empezo.
        ->and($derivadoPresencial['totals'][1]['total_minutes'])->toBe(480);

    expect($derivadoRemota)->toEqual($derivadoPresencial)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-GP-01', 'RN-05', 'RN-06');
