<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Infrastructure\Persistence\DatabaseCardFingerprintOwners;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\QueryPlans;

/*
 * **La atribucion por huella entra por su indice** (RN-22, ADR-061 A2, RS-03).
 *
 * Sin indice, buscar al titular de una tarjeta recorria todos los fichajes de la
 * ventana y su duracion dependia de cuanto se habia usado la tarjeta: un canal
 * lateral. Con miles de fichajes de otras tarjetas y estadisticas reales
 * (`ANALYZE`), el plan tiene que usar `scan_events_accepted_card_fingerprint_index`
 * y no recorrer `scan_events`.
 */

uses(CommittedDatabase::class);

const HUELLA_BUSCADA = 'aa11bb22cc33dd44ee55ff6600112233445566778899aabbccddeeff00112233';

it('encuentra al titular de la huella por su indice parcial y sin recorrer scan_events', function (): void {
    $escenario = AttendanceFixtures::scenario();
    $empleado = AttendanceFixtures::employeeIdOf($escenario['employee']);

    $filas = [];

    for ($i = 0; $i < 5_000; $i++) {
        $filas[] = [
            'scan_id' => Str::uuid7()->toString(),
            'device_id' => $escenario['device'],
            'employee_id' => $empleado,
            'occurred_at' => (new DateTimeImmutable('2026-03-14 07:00:00 UTC'))->modify('-'.($i % 170).' days')->modify('+'.$i.' seconds')->format('Y-m-d H:i:sP'),
            'recorded_at' => '2026-03-14 07:00:00+00',
            'origin' => 'qr_kiosk',
            'intent' => 'auto',
            'result' => 'clock_in',
            'worked_minutes' => 0,
            'payload_fingerprint' => $i === 4_321 ? HUELLA_BUSCADA : hash('sha256', 'tarjeta-'.$i),
        ];
    }

    foreach (array_chunk($filas, 1_000) as $tanda) {
        DB::table('scan_events')->insert($tanda);
    }

    QueryPlans::analyze('scan_events', 'employees');

    $huellas = new DatabaseCardFingerprintOwners(DB::connection());
    $dueno = null;

    $consultas = QueryPlans::selectsOn('scan_events', static function () use ($huellas, &$dueno): void {
        $dueno = $huellas->latestAcceptedOwner(
            HUELLA_BUSCADA,
            new DateTimeImmutable('2025-09-15 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-03-15 00:00:00', new DateTimeZone('UTC')),
        );
    });

    expect($dueno)->toBe($escenario['employee'])
        ->and($consultas)->toHaveCount(1);

    $nodos = QueryPlans::nodes($consultas[0]);

    expect($nodos)->not->toBeEmpty('El plan de ejecucion no se pudo leer')
        ->and(QueryPlans::usesIndex($nodos, 'scan_events_accepted_card_fingerprint_index'))->toBeTrue('La atribucion por huella no usa su indice')
        ->and(QueryPlans::scansSequentially($nodos, 'scan_events'))->toBeFalse('La atribucion por huella recorre scan_events');
})->group('RN-22', 'RS-03', 'RNF-P-02');

it('escribe en la consulta y en el indice exactamente los resultados aceptados', function (): void {
    // El predicado va literal en los dos sitios (ver el docblock del adaptador):
    // si `ScanResult` gana un aceptado, esta prueba obliga a tocar ambos.
    $indice = DB::table('pg_indexes')->where('indexname', 'scan_events_accepted_card_fingerprint_index')->value('indexdef');
    $definicion = \is_string($indice) ? $indice : '';
    $consulta = (string) file_get_contents(base_path('app/Modules/Attendance/Infrastructure/Persistence/DatabaseCardFingerprintOwners.php'));

    foreach (ScanResult::acceptedValues() as $aceptado) {
        expect($definicion)->toContain("'".$aceptado."'")
            ->and($consulta)->toContain("'".$aceptado."'");
    }

    expect(substr_count($definicion, '::text'))->toBeGreaterThanOrEqual(\count(ScanResult::acceptedValues()));
})->group('RN-22');
