<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\RetentionMode;
use App\Modules\Compliance\Domain\ValueObject\RetentionPolicySnapshot;
use App\Modules\Compliance\Domain\ValueObject\RetentionReport;

/*
 * La frase de confirmacion de la purga sale de todo lo que decide que se borra
 * o se minimiza (RF-PR-03, ADR-060): si cambian los años de la ficha o los
 * cortes de la ficha y de la cuenta, la frase aprobada ayer ya no vale.
 */

/**
 * @param  list<int>  $partitions
 */
function frasePurga(
    int $personnelYears = 4,
    ?string $personnelCutoff = '2022-10-22',
    ?string $accountCutoff = '2022-10-22',
    array $partitions = [2021],
    int $legalYears = 4,
    int $technicalDays = 90,
    int $errorDays = 90,
): string {
    $utc = new DateTimeZone('UTC');

    return new RetentionReport(
        mode: RetentionMode::Simulation,
        generatedAt: new DateTimeImmutable('2026-10-22 03:00:00', $utc),
        policy: new RetentionPolicySnapshot($legalYears, $technicalDays, $errorDays, 1, 4, 'ES', $personnelYears),
        workRecordCutoff: new DateTimeImmutable('2022-10-22 00:00:00', $utc),
        auditPartitionYears: $partitions,
        shortCycleCutoffs: [],
        tallies: [],
        personnelRecordCutoff: $personnelCutoff === null ? null : new DateTimeImmutable($personnelCutoff.' 00:00:00', $utc),
        managementAccountCutoff: $accountCutoff === null ? null : new DateTimeImmutable($accountCutoff.' 00:00:00', $utc),
    )->confirmationToken();
}

it('dos informes iguales dan la misma frase, con el corte del registro delante', function (): void {
    expect(frasePurga())->toBe(frasePurga())
        ->and(frasePurga())->toMatch('/^PURGAR-2022-10-22-[0-9a-f]{6}$/');
})->group('RF-PR-03', 'RL-11');

it('es estable: la misma entrada da siempre la misma frase, escrita aqui a mano', function (): void {
    // El valor fijo es lo que impide cambiar el algoritmo sin darse cuenta: una
    // frase aprobada ayer tiene que seguir siendo la de hoy con el mismo plan.
    expect(frasePurga())->toBe('PURGAR-2022-10-22-4b099c');
})->group('RF-PR-03');

it('cambia con cada dato del plazo y de las particiones', function (string $frase): void {
    expect($frase)->not->toBe(frasePurga());
})->with([
    'otra particion' => [frasePurga(partitions: [2020, 2021])],
    'otros años del registro' => [frasePurga(legalYears: 5)],
    'otros dias del log tecnico' => [frasePurga(technicalDays: 60)],
    'otros dias del historico de errores' => [frasePurga(errorDays: 60)],
])->group('RF-PR-03', 'RL-11');

it('dos informes que solo difieren en los años de la ficha dan frases distintas', function (): void {
    expect(frasePurga(personnelYears: 6))->not->toBe(frasePurga(personnelYears: 4));
})->group('RF-PR-03', 'RL-11');

it('dos informes que solo difieren en el corte de la ficha dan frases distintas', function (): void {
    expect(frasePurga(personnelCutoff: '2020-10-22'))->not->toBe(frasePurga())
        ->and(frasePurga(personnelCutoff: null))->not->toBe(frasePurga());
})->group('RF-PR-03', 'RL-11');

it('dos informes que solo difieren en el corte de la cuenta dan frases distintas', function (): void {
    expect(frasePurga(accountCutoff: '2021-10-22'))->not->toBe(frasePurga())
        ->and(frasePurga(accountCutoff: null))->not->toBe(frasePurga());
})->group('RF-PR-03', 'RL-11');
