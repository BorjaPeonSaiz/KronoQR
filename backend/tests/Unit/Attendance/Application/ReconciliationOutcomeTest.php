<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ProjectedDailyTotal;
use App\Modules\Attendance\Application\UseCase\CorrectionAttempt;
use App\Modules\Attendance\Application\UseCase\CorrectionOutcome;
use App\Modules\Attendance\Application\UseCase\DailyTotalsDivergence;
use App\Modules\Attendance\Application\UseCase\ReconciliationReport;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;

/*
 * **Los cuatro desenlaces de una jornada sospechosa** (RF-PR-02, RN-06).
 *
 * Por que esto es unitario: la diferencia entre «corregida», «resuelta sola»,
 * «fallida» y «no se pudo ni mirar» es una decision, no una consulta. De ella
 * dependen tres cosas que se leen desde fuera —`projection_divergence_total`, el
 * codigo de salida del comando y el asiento de `audit_log`— y ninguna necesita
 * base de datos para comprobarse. Probarla solo desde la prueba de concurrencia
 * dejaria la regla viva en el sitio mas caro de ejecutar.
 *
 * Lo que hay detras: la pasada nocturna se cruza con el turno de noche y ve
 * divergencias que no lo son, y compite por el candado de la cadena de auditoria
 * con los fichajes (ver `ReconciliationConcurrencyTest`). Contar esas dos cosas
 * como divergencias encenderia la alerta critica de integridad cada madrugada;
 * esconderlas dejaria sin explicacion una proyeccion que parecio torcida y una
 * pasada que no termino su trabajo.
 */

/**
 * Una divergencia cualquiera: la fila que falta, que es la mas simple de
 * construir y la unica que no necesita inventarse una fila escrita.
 */
function missingRowDivergence(): DailyTotalsDivergence
{
    $expected = new DailyTotalsRecalculated(
        '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Europe/Madrid')),
        WorkedDuration::ofMinutes(480),
        1,
        new DateTimeImmutable('2026-03-14 06:00:00', new DateTimeZone('UTC')),
        new DateTimeImmutable('2026-03-14 14:00:00', new DateTimeZone('UTC')),
        false,
        false,
        new DateTimeImmutable('2026-03-15 03:50:00', new DateTimeZone('UTC')),
    );

    return DailyTotalsDivergence::between($expected, null)
        ?? throw new RuntimeException('Una jornada con tramos y sin fila SIEMPRE es divergencia.');
}

/**
 * Una pasada con los recuentos que se le pasen, y el resto a cero.
 */
function reconciliationPassWith(int $divergences, int $corrected, int $failures, int $selfResolved): ReconciliationReport
{
    return ReconciliationReport::of(
        fromIsoDate: '2026-03-14',
        toIsoDate: '2026-03-14',
        daysInspected: 1,
        workDaysInspected: 30,
        divergences: $divergences,
        corrected: $corrected,
        failures: $failures,
        selfResolved: $selfResolved,
        byField: [],
    );
}

it('cuenta como divergencia la que se corrigio, con sus columnas', function (): void {
    $divergence = missingRowDivergence();

    $attempt = CorrectionAttempt::corrected($divergence);

    // La confirmada y no la sospechada: es la que decide las columnas del
    // informe y la que viaja al asiento de auditoria.
    expect($attempt->outcome)->toBe(CorrectionOutcome::Corrected)
        ->and($attempt->divergence)->toBe($divergence)
        ->and($attempt->outcome->countsAsDivergence())->toBeTrue()
        ->and($attempt->outcome->countsAsFailure())->toBeFalse()
        ->and($attempt->divergentFields())->toBe(['row']);
})->group('RF-PR-02', 'RN-06');

it('no cuenta nada cuando la sospecha se deshace sola', function (): void {
    $attempt = CorrectionAttempt::resolvedItself();

    // Sin divergencia y sin fallo: no se escribio nada porque no habia nada que
    // escribir. Si contara como divergencia, la alerta critica de integridad
    // sonaria cada madrugada en un hotel con turno de noche.
    expect($attempt->outcome)->toBe(CorrectionOutcome::ResolvedItself)
        ->and($attempt->divergence)->toBeNull()
        ->and($attempt->outcome->countsAsDivergence())->toBeFalse()
        ->and($attempt->outcome->countsAsFailure())->toBeFalse()
        ->and($attempt->divergentFields())->toBe([]);
})->group('RF-PR-02', 'RN-06');

it('cuenta como divergencia y como fallo la que no se pudo corregir', function (): void {
    $divergence = missingRowDivergence();

    $attempt = CorrectionAttempt::failed($divergence);

    // Fallar no es «no habia nada»: la jornada sigue divergiendo y tiene que
    // contarse y salir en el informe, o la unica constancia de que quedo algo
    // sin hacer seria el codigo de salida.
    expect($attempt->outcome)->toBe(CorrectionOutcome::Failed)
        ->and($attempt->divergence)->toBe($divergence)
        ->and($attempt->outcome->countsAsDivergence())->toBeTrue()
        ->and($attempt->outcome->countsAsFailure())->toBeTrue()
        ->and($attempt->divergentFields())->toBe(['row']);
})->group('RF-PR-02', 'RN-06');

it('cuenta la contencion como fallo y nunca como divergencia', function (): void {
    // `55P03` (no llego el candado dentro de `lock_timeout`) y `40P01` (abrazo
    // mortal roto por PostgreSQL) dicen lo mismo: esa jornada **no se comparo**.
    // Es trabajo sin hacer —el comando sale en rojo— pero no dice nada sobre la
    // integridad de la tabla, y contarlo en `projection_divergence_total`
    // convertiria un pico de fichajes en un incidente de integridad.
    $attempt = CorrectionAttempt::contended(missingRowDivergence());

    expect($attempt->outcome)->toBe(CorrectionOutcome::Contended)
        ->and($attempt->outcome->countsAsDivergence())->toBeFalse()
        ->and($attempt->outcome->countsAsFailure())->toBeTrue()
        // Lleva la sospecha para poder decir QUE jornada se quedo sin revisar,
        // pero sus columnas no van al informe: no se confirmo ninguna.
        ->and($attempt->divergence)->not->toBeNull()
        ->and($attempt->divergentFields())->toBe([]);
})->group('RF-PR-02', 'RN-06');

it('sigue siendo una pasada limpia por muchas sospechas que se deshagan solas', function (): void {
    $report = reconciliationPassWith(divergences: 0, corrected: 0, failures: 0, selfResolved: 7);

    // Siete carreras con el turno de noche no son siete incidentes: no se
    // escribio ni una fila. Si esto ensuciara la pasada, el comando terminaria
    // en rojo cada madrugada y la senal dejaria de significar nada.
    expect($report->isClean())->toBeTrue()
        ->and($report->selfResolved)->toBe(7)
        ->and($report->divergences)->toBe(0);
})->group('RF-PR-02', 'RN-06');

it('no es una pasada limpia si algo divergia de verdad o quedo sin corregir', function (): void {
    expect(reconciliationPassWith(divergences: 1, corrected: 1, failures: 0, selfResolved: 4)->isClean())->toBeFalse()
        // Contencion: cero divergencias y un fallo. La pasada NO esta limpia
        // aunque la proyeccion pueda estar perfecta.
        ->and(reconciliationPassWith(divergences: 0, corrected: 0, failures: 1, selfResolved: 0)->isClean())->toBeFalse();
})->group('RF-PR-02', 'RN-06');

it('no cuenta nada antes de que haya centro de trabajo', function (): void {
    // Antes de la puesta en marcha no hay zona horaria con la que decir a que
    // jornada pertenece un turno (RF-PD-03, RN-05): no hay nada que reconciliar
    // y, sobre todo, nada que se haya resuelto solo.
    $report = ReconciliationReport::withoutSite();

    expect($report->ranOverASite)->toBeFalse()
        ->and($report->selfResolved)->toBe(0)
        ->and($report->isClean())->toBeTrue();
})->group('RF-PR-02');

it('distingue una fila ausente de una fila escrita con otros valores', function (): void {
    // La guarda de que la divergencia de estas pruebas es la que se cree: si
    // `between()` dejara de ver la fila ausente como divergencia, los cuatro
    // desenlaces de arriba se estarian probando sobre un objeto imposible.
    $divergence = missingRowDivergence();

    expect($divergence->rowWasMissing())->toBeTrue()
        ->and($divergence->fields)->toBe(['row'])
        ->and($divergence->actual)->not->toBeInstanceOf(ProjectedDailyTotal::class);
})->group('RF-PR-02', 'RN-06');
