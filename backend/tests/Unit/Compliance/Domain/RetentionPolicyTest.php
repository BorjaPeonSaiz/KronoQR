<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\Exception\InvalidRetentionPolicy;
use App\Modules\Compliance\Domain\Policy\RetentionPolicy;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;

/*
 * La decision de que esta vencido (RL-02, RL-11, RF-PR-03, tarea 2.10).
 *
 * ES UNITARIA Y NO DE INTEGRACION porque lo que se comprueba es aritmetica de
 * calendario con el reloj inyectado: no hay tabla, no hay conexion y no hay
 * borrado. Lo que si hay son **valores limite**, que es donde una purga se lleva
 * por delante una jornada que todavia habia que conservar.
 *
 * EL RELOJ ES FIJO (regla dura 2). Una prueba de retencion que llamara a `now()`
 * pasaria trescientos sesenta y cuatro dias al ano y fallaria uno.
 */

/** Instante de referencia de todo el fichero, en UTC (regla dura 3). */
function retentionNow(string $moment = '2030-08-30 03:00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
}

function workDate(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC'));
}

it('conserva la jornada de cuatro anos menos un dia y purga la de cuatro anos y un dia', function (): void {
    // El limite explicito que exige la tarea. Con cuatro anos de retencion y el
    // reloj en el 30 de agosto de 2030:
    $policy = RetentionPolicy::of(legalRecordYears: 4, technicalLogDays: 90, errorHistoryDays: 90);
    $now = retentionNow();

    expect($policy->workRecordCutoff($now)->format('Y-m-d'))->toBe('2026-08-30')
        // Cuatro anos MENOS UN DIA: dentro del plazo, no se purga.
        ->and($policy->purgesWorkDate(workDate('2026-08-31'), $now))->toBeFalse()
        // EL DIA EXACTO en que cumple los cuatro anos: tambien dentro. El
        // art. 34.9 ET obliga a conservar «durante cuatro anos» y el ultimo dia
        // pertenece al plazo. Ante la duda, se conserva.
        ->and($policy->purgesWorkDate(workDate('2026-08-30'), $now))->toBeFalse()
        // Cuatro anos Y UN DIA: vencida.
        ->and($policy->purgesWorkDate(workDate('2026-08-29'), $now))->toBeTrue();
})->group('RL-02', 'RF-PR-03');

it('mueve el corte cuando el perfil de cumplimiento fija otro plazo, sin tocar el codigo', function (): void {
    // Regla dura 14 y ADR-017: los anos son un dato del perfil, no una constante.
    // La misma jornada se conserva o se purga segun el perfil que sirva el
    // centro, y aqui no hay ni un `4` escrito en el dominio.
    $now = retentionNow();
    $spanish = RetentionPolicy::of(legalRecordYears: 4, technicalLogDays: 90, errorHistoryDays: 90);
    $longer = RetentionPolicy::of(legalRecordYears: 6, technicalLogDays: 30, errorHistoryDays: 30);

    $vencida = workDate('2026-08-29');

    expect($spanish->purgesWorkDate($vencida, $now))->toBeTrue()
        ->and($longer->purgesWorkDate($vencida, $now))->toBeFalse()
        ->and($longer->workRecordCutoff($now)->format('Y-m-d'))->toBe('2024-08-30')
        // Y el ciclo corto se mueve igual: 30 dias en lugar de 90.
        ->and($longer->shortCycleCutoff(RetentionScope::ErrorHistory, $now)->format('Y-m-d'))->toBe('2030-07-31')
        ->and($spanish->shortCycleCutoff(RetentionScope::ErrorHistory, $now)->format('Y-m-d'))->toBe('2030-06-01');
})->group('RF-PD-07', 'RL-11');

it('no suelta una particion de audit_log hasta que vence entera', function (): void {
    // ADR-027: `DROP PARTITION` es todo o nada, asi que el redondeo va hacia
    // conservar de mas. En agosto de 2030 la particion de 2026 tiene asientos de
    // septiembre a diciembre que aun no han cumplido cuatro anos.
    $policy = RetentionPolicy::of(legalRecordYears: 4, technicalLogDays: 90, errorHistoryDays: 90);

    expect($policy->purgesAuditPartition(2026, retentionNow()))->toBeFalse()
        // El 1 de enero de 2031, el ultimo instante de 2026 ya esta fuera.
        ->and($policy->purgesAuditPartition(2026, retentionNow('2031-01-01 00:00:00')))->toBeTrue()
        ->and($policy->purgesAuditPartition(2027, retentionNow('2031-01-01 00:00:00')))->toBeFalse();
})->group('RL-02', 'RS-07');

it('cuenta el ciclo corto en dias sobre el instante, no sobre la fecha de jornada', function (): void {
    $policy = RetentionPolicy::of(legalRecordYears: 4, technicalLogDays: 90, errorHistoryDays: 45);
    $now = retentionNow('2030-08-30 11:30:00');

    expect($policy->shortCycleCutoff(RetentionScope::TechnicalLog, $now)->format('Y-m-d H:i'))
        ->toBe('2030-06-01 11:30')
        ->and($policy->shortCycleCutoff(RetentionScope::ErrorHistory, $now)->format('Y-m-d H:i'))
        ->toBe('2030-07-16 11:30')
        ->and($policy->daysFor(RetentionScope::ErrorHistory))->toBe(45);
})->group('RL-11');

it('se niega a nacer con un plazo que significaria purgarlo todo', function (): void {
    // Un cero en el perfil de cumplimiento no es «sin limite»: es «bórralo todo».
    // Tiene que detenerse en el constructor y no cuando ya hay una fecha de corte
    // calculada con el.
    expect(fn (): RetentionPolicy => RetentionPolicy::of(0, 90, 90))
        ->toThrow(InvalidRetentionPolicy::class)
        ->and(fn (): RetentionPolicy => RetentionPolicy::of(4, 0, 90))
        ->toThrow(InvalidRetentionPolicy::class)
        ->and(fn (): RetentionPolicy => RetentionPolicy::of(4, 90, -1))
        ->toThrow(InvalidRetentionPolicy::class);
})->group('RL-11');

it('no responde en dias por un ambito que se mide en anos', function (): void {
    // El `match` sin `default` de la politica: un ambito nuevo obliga a decidir su
    // plazo, y pedir dias a uno legal es un error de programacion, no un caso.
    $policy = RetentionPolicy::of(4, 90, 90);

    expect(fn (): int => $policy->daysFor(RetentionScope::WorkRecords))
        ->toThrow(InvalidRetentionPolicy::class);
})->group('RL-11');
