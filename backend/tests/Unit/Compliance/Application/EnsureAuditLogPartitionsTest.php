<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\EnsureAuditLogPartitions;
use Tests\Support\Compliance\InMemoryAuditLogPartitions;
use Tests\Support\Compliance\RecordingAuditMetrics;
use Tests\Support\Time\FixedClock;

/*
 * El calendario de particiones de ADR-027, con reloj fijo y sin base de datos.
 *
 * Es la clase de regla que no se puede probar esperando: «crea la del año que
 * viene en noviembre» solo se comprueba de verdad el 1 de noviembre, y para
 * entonces, si esta mal, el fichaje se para el 1 de enero.
 */

/**
 * @param  list<int>  $existing
 * @return array{0: EnsureAuditLogPartitions, 1: InMemoryAuditLogPartitions, 2: RecordingAuditMetrics}
 */
function partitionScenario(string $now, array $existing): array
{
    $partitions = new InMemoryAuditLogPartitions($existing);
    $metrics = new RecordingAuditMetrics;

    return [
        new EnsureAuditLogPartitions($partitions, $metrics, FixedClock::at($now)),
        $partitions,
        $metrics,
    ];
}

it('no toca nada si el año en curso ya tiene particion y no es noviembre', function (): void {
    [$ensure, $partitions] = partitionScenario('2026-06-15 02:45:00', [2026]);

    $status = $ensure->handle();

    expect($partitions->created)->toBe([])
        ->and($status->isHealthy())->toBeTrue()
        ->and($status->nextYearReady)->toBeFalse();
})->group('RS-07');

it('crea la particion del año siguiente a partir de noviembre', function (): void {
    // Dos meses de margen sobre el 1 de enero, y en noviembre y no en diciembre
    // para que un fallo se vea antes de las vacaciones de Navidad, que es cuando
    // menos gente hay para atenderlo y mas turnos se hacen.
    [$ensure, $partitions] = partitionScenario('2026-11-01 02:45:00', [2026]);

    $status = $ensure->handle();

    expect($partitions->created)->toBe([2027])
        ->and($status->nextYearReady)->toBeTrue()
        ->and($status->isHealthy())->toBeTrue();
})->group('RS-07');

it('no crea la del año siguiente dos veces', function (): void {
    [$ensure, $partitions] = partitionScenario('2026-12-31 23:59:59', [2026, 2027]);

    $status = $ensure->handle();

    expect($partitions->created)->toBe([])
        ->and($status->nextYearReady)->toBeTrue();
})->group('RS-07');

it('crea la del año en curso si falta y lo declara como fallo', function (): void {
    // Que faltara significa que hasta este momento TODA accion auditable estaba
    // fallando: un INSERT sin particion de destino aborta y arrastra la
    // transaccion de la accion. Crearla resuelve el presente y no borra el
    // hecho.
    [$ensure, $partitions, $metrics] = partitionScenario('2027-03-04 02:45:00', [2026]);

    $status = $ensure->handle();

    expect($partitions->created)->toBe([2027])
        ->and($status->currentYearWasMissing)->toBeTrue()
        ->and($status->isHealthy())->toBeFalse()
        ->and($metrics->lastPartitionStatus?->currentYearWasMissing)->toBeTrue();
})->group('RS-07');

it('usa el año UTC y no el de la zona del centro', function (): void {
    // Regla dura 3. En Madrid ya es 2027 cuando en UTC todavia es 2026: si el
    // calculo usara la hora local, la ultima hora del año se escribiria en la
    // particion equivocada, o en ninguna.
    [$ensure] = partitionScenario('2026-12-31 23:30:00', [2026, 2027]);

    expect($ensure->handle()->currentYear)->toBe(2026);
})->group('RS-07');

it('publica el estado como metrica en cada pasada', function (): void {
    [$ensure, , $metrics] = partitionScenario('2026-06-15 02:45:00', [2026]);

    $ensure->handle();

    expect($metrics->lastPartitionStatus)->not->toBeNull()
        ->and($metrics->lastPartitionStatus?->currentYear)->toBe(2026);
})->group('RS-07');
