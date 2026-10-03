<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\Command\ScanBatch;
use App\Modules\Attendance\Application\Port\ScanIntent;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Application\UseCase\RegisterScanBatchHandler;
use App\Modules\Attendance\Application\UseCase\RegisterScanResult;
use App\Modules\Attendance\Application\UseCase\ScanBatchOutcome;
use App\Modules\Attendance\Application\UseCase\ScanRegistration;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use Psr\Log\NullLogger;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Time\FixedClock;

/*
 * **RN-21 en el orquestador del lote** (ADR-047), sin base de datos.
 *
 * Tras el primer elemento no procesado, los posteriores del mismo lote **no
 * llegan al caso de uso**: salen aplazados en su orden. Lo ya decidido antes no se
 * toca y un rechazo (`422`) no detiene nada. El doble cuenta las llamadas, que es
 * lo unico que demuestra que un aplazado no se ha mirado.
 */

/**
 * Doble del caso de uso: falla en los `scan_id` indicados y acepta el resto.
 */
final class RegistroDeEscaneosDeLote implements ScanRegistration
{
    /** @var list<string> */
    public array $llamadas = [];

    /**
     * @param  list<string>  $fallan
     * @param  list<string>  $rechaza
     */
    public function __construct(private array $fallan = [], private array $rechaza = []) {}

    public function handle(RegisterScanCommand $command): RegisterScanResult
    {
        $this->llamadas[] = $command->scanId;

        if (in_array($command->scanId, $this->fallan, true)) {
            throw new RuntimeException('Fallo transitorio de la base de datos.');
        }

        if (in_array($command->scanId, $this->rechaza, true)) {
            return RegisterScanResult::rejected($command->scanId, ScanResult::REJECTED_REVOKED, $command->occurredAt, $command->occurredAt);
        }

        return RegisterScanResult::accepted(
            scanId: $command->scanId,
            result: ScanResult::CLOCK_IN,
            occurredAt: $command->occurredAt,
            recordedAt: $command->occurredAt,
            employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
            employeeDisplayName: 'Maria G.',
            workDate: '2026-08-14',
            workedMinutes: 0,
        );
    }
}

function escaneoAplazable(string $scanId, string $occurredAt): RegisterScanCommand
{
    return new RegisterScanCommand(
        scanId: $scanId,
        qrPayload: 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
        occurredAt: new DateTimeImmutable($occurredAt, new DateTimeZone('UTC')),
        deviceId: 1,
        deviceUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        origin: ScanOrigin::QR_KIOSK,
        intent: ScanIntent::AUTO,
    );
}

/**
 * @return list<string>
 */
function desenlacesDeLote(ScanBatchOutcome ...$outcomes): array
{
    return array_values(array_map(static fn (ScanBatchOutcome $outcome): string => match (true) {
        $outcome->wasHeldBack() => 'aplazado',
        ! $outcome->wasProcessed() => 'no_procesado',
        default => 'procesado',
    }, $outcomes));
}

const LOTE_APLAZADO_IDS = [
    '0199f0c2-0000-7000-8000-000000000001',
    '0199f0c2-0000-7000-8000-000000000002',
    '0199f0c2-0000-7000-8000-000000000003',
    '0199f0c2-0000-7000-8000-000000000004',
];

/**
 * @return list<RegisterScanCommand>
 */
function loteDeCuatro(): array
{
    return [
        escaneoAplazable(LOTE_APLAZADO_IDS[0], '2026-08-14T07:00:00Z'),
        escaneoAplazable(LOTE_APLAZADO_IDS[1], '2026-08-14T08:00:00Z'),
        escaneoAplazable(LOTE_APLAZADO_IDS[2], '2026-08-14T09:00:00Z'),
        escaneoAplazable(LOTE_APLAZADO_IDS[3], '2026-08-14T10:00:00Z'),
    ];
}

it('aplaza todo lo que viene detras del primer elemento no procesado sin llamar al caso de uso', function (): void {
    $registro = new RegistroDeEscaneosDeLote(fallan: [LOTE_APLAZADO_IDS[1]]);
    $metricas = new RecordingScanMetrics;

    $desenlaces = (new RegisterScanBatchHandler($registro, $metricas, FixedClock::at('2026-08-14 18:00:00'), new NullLogger))
        ->handle(ScanBatch::of(loteDeCuatro()));

    expect(desenlacesDeLote(...$desenlaces))->toBe(['procesado', 'no_procesado', 'aplazado', 'aplazado'])
        // El caso de uso vio los dos primeros y ninguno mas.
        ->and($registro->llamadas)->toBe([LOTE_APLAZADO_IDS[0], LOTE_APLAZADO_IDS[1]])
        // En su orden, emparejables por `scan_id`.
        ->and(array_map(static fn (ScanBatchOutcome $o): string => $o->scanId, $desenlaces))->toBe(LOTE_APLAZADO_IDS)
        // Un aplazado no es un escaneo procesado: no cuenta en `scans_total`.
        ->and($metricas->observations)->toHaveCount(1)
        // Y el que fallo, una vez, en `scan_batch_items_not_processed_total`.
        ->and($metricas->notProcessed)->toBe(['0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90']);
})->group('RN-21', 'RF-KI-04', 'RF-AT-07');

it('no aplaza nada detras de un rechazo, que es un desenlace', function (): void {
    $registro = new RegistroDeEscaneosDeLote(rechaza: [LOTE_APLAZADO_IDS[0]]);

    $desenlaces = (new RegisterScanBatchHandler($registro, new RecordingScanMetrics, FixedClock::at('2026-08-14 18:00:00'), new NullLogger))
        ->handle(ScanBatch::of(loteDeCuatro()));

    expect(desenlacesDeLote(...$desenlaces))->toBe(['procesado', 'procesado', 'procesado', 'procesado'])
        ->and($registro->llamadas)->toHaveCount(4)
        ->and($desenlaces[0]->result?->isRejected())->toBeTrue();
})->group('RN-21', 'RF-KI-04');

it('distingue el aplazado del no procesado aunque los dos sean pendientes', function (): void {
    $aplazado = ScanBatchOutcome::heldBack(LOTE_APLAZADO_IDS[0]);
    $noProcesado = ScanBatchOutcome::notProcessed(LOTE_APLAZADO_IDS[1]);

    expect($aplazado->wasProcessed())->toBeFalse()
        ->and($aplazado->wasHeldBack())->toBeTrue()
        ->and($aplazado->result)->toBeNull()
        ->and($noProcesado->wasProcessed())->toBeFalse()
        ->and($noProcesado->wasHeldBack())->toBeFalse();
})->group('RN-21', 'RF-KI-04');

it('aplaza el lote entero si falla el primero', function (): void {
    $registro = new RegistroDeEscaneosDeLote(fallan: [LOTE_APLAZADO_IDS[0]]);

    $desenlaces = (new RegisterScanBatchHandler($registro, new RecordingScanMetrics, FixedClock::at('2026-08-14 18:00:00'), new NullLogger))
        ->handle(ScanBatch::of(loteDeCuatro()));

    expect(desenlacesDeLote(...$desenlaces))->toBe(['no_procesado', 'aplazado', 'aplazado', 'aplazado'])
        ->and($registro->llamadas)->toBe([LOTE_APLAZADO_IDS[0]]);
})->group('RN-21', 'RF-KI-04');
