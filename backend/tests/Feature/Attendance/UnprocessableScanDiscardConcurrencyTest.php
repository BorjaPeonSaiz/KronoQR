<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Application\Port\SiteCalendar;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FrozenTime;

/*
 * **Diez reenvios simultaneos del fichaje que cruza el umbral dejan UN descarte
 * y diez respuestas identicas** (RN-22, ADR-061, regla dura 8; doc 02 §9.5:
 * una escritura del quiosco exige idempotencia concurrente).
 *
 * Diez procesos de verdad contra PostgreSQL, con la base confirmada: la
 * idempotencia la decide el `UNIQUE` de `scan_events.scan_id`, y el que pierde
 * la carrera encuentra el descarte del que gano y lo repite en lugar de
 * responder `500`.
 */

uses(CommittedDatabase::class);

const DESCARTE_CONCURRENTE_TARJETA = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

const DESCARTE_CONCURRENTE_PETICIONES = 10;

it('deja un solo descarte, un solo aviso y un solo asiento con diez reenvios simultaneos', function (): void {
    $escenario = AttendanceFixtures::scenario();

    FrozenTime::at('2026-03-14 08:00:00');
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    app()->instance(CredentialResolver::class, new class($escenario['employee']) implements CredentialResolver
    {
        public function __construct(private readonly string $employee) {}

        public function resolve(string $qrPayload): CredentialResolution
        {
            return CredentialResolution::resolved($this->employee, new DateTimeImmutable('2026-01-01T00:00:00Z'));
        }
    });
    app()->instance(SiteCalendar::class, new class implements SiteCalendar
    {
        public function timezoneOf(int $siteId): ?DateTimeZone
        {
            throw new LogicException('Defecto reproducible.');
        }

        public function timezoneForRecording(int $siteId): ?DateTimeZone
        {
            throw new LogicException('Defecto reproducible.');
        }
    });

    $scanId = Str::uuid7()->toString();

    // Nueve fallos ya contados, el primero hace 61 minutos: cualquiera de los
    // diez envios cruza el umbral.
    for ($i = 0; $i < 9; $i++) {
        DB::table('scan_processing_failures')->insert([
            'scan_id' => $scanId,
            'device_id' => $escenario['device'],
            'origin' => 'qr_kiosk',
            'payload_fingerprint' => hash('sha256', DESCARTE_CONCURRENTE_TARJETA),
            'failed_at' => '2026-03-14 06:59:00+00',
            'exception_class' => LogicException::class,
        ]);
    }

    $respuestas = ParallelRequests::run(
        DESCARTE_CONCURRENTE_PETICIONES,
        static fn (): mixed => Api::as($escenario['token'])
            ->withHeaders(['Idempotency-Key' => $scanId])
            ->post('/api/v1/scan', [
                'scan_id' => $scanId,
                'occurred_at' => '2026-03-14T07:00:00Z',
                'qr_payload' => DESCARTE_CONCURRENTE_TARJETA,
            ]),
    );

    $codigos = array_map(static fn (array $r): int => $r['status'], $respuestas);
    $cuerpos = array_map(static fn (array $r): string => json_encode($r['body'], JSON_THROW_ON_ERROR), $respuestas);

    expect(array_values(array_unique($codigos)))->toBe([422])
        ->and(array_values(array_unique($cuerpos)))->toHaveCount(1)
        ->and($respuestas[0]['body'])->toMatchArray(['type' => 'urn:kronoqr:problem:scan-discarded', 'scan_id' => $scanId])
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->count())->toBe(1)
        ->and(DB::table('discarded_scan_reports')->where('scan_id', $scanId)->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'scan.discarded')->count())->toBe(1)
        ->and(DB::table('shift_entries')->count())->toBe(0);
})->group('RN-22', 'RF-AT-07', 'RQ-03');
