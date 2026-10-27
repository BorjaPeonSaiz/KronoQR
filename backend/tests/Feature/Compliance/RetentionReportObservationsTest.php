<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\RetentionMode;
use App\Modules\Compliance\Domain\ValueObject\RetentionNote;
use App\Modules\Compliance\Domain\ValueObject\RetentionPolicySnapshot;
use App\Modules\Compliance\Domain\ValueObject\RetentionReport;
use App\Modules\Compliance\Infrastructure\Retention\FileRetentionReportStore;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\InstallationLocale;
use Tests\Support\Shared\GeneratedFilesSandbox;

/*
 * Las observaciones del informe de purga salen en el idioma de la instalacion
 * (RF-PR-03, RF-KI-05). El caso de uso guarda que paso y con que cifras
 * (`RetentionNote`); la frase la pone quien escribe el informe.
 */

// `InstallationLocale` escribe `installation_settings`: sin la transaccion de
// cada prueba, el idioma ingles quedaria activo para el resto de la suite.
uses(RefreshDatabase::class);

afterEach(function (): void {
    GeneratedFilesSandbox::cleanUp();
});

function observacionesInformeEjecutado(): RetentionReport
{
    return new RetentionReport(
        mode: RetentionMode::Execution,
        generatedAt: new DateTimeImmutable('2026-10-02T05:10:00Z'),
        policy: new RetentionPolicySnapshot(4, 90, 90, 1, 4, 'ES', 4),
        workRecordCutoff: new DateTimeImmutable('2022-01-01T00:00:00Z'),
        auditPartitionYears: [2020],
        shortCycleCutoffs: [],
        tallies: [],
        notes: [RetentionNote::emptyAuditPartition(2020), RetentionNote::orphanedPersonnelPhotos(3)],
    );
}

it('escribe las observaciones con sus cifras en el idioma de la instalacion', function (string $locale, string $title, string $partition, string $photos): void {
    InstallationLocale::set($locale);
    config(['compliance.retention.report_path' => GeneratedFilesSandbox::directory('retention')]);

    $texto = (string) file_get_contents(app(FileRetentionReportStore::class)->store(observacionesInformeEjecutado()));

    expect($texto)->toContain($title)
        ->and($texto)->toContain('  · '.$partition)
        ->and($texto)->toContain('  · '.$photos)
        ->and($texto)->not->toContain('observations.');
})->with([
    'es' => ['es', 'Observaciones', 'Partición audit_log_2020 vacía: no se sella ni se suelta.', '3 foto(s) de fichas minimizadas'],
    'en' => ['en', 'Notes', 'Partition audit_log_2020 is empty: it is neither sealed nor released.', '3 photo(s) of minimised personnel records'],
])->group('RF-PR-03', 'RF-KI-05');

it('no escribe la seccion de observaciones cuando no hay ninguna', function (): void {
    InstallationLocale::set('es');
    config(['compliance.retention.report_path' => GeneratedFilesSandbox::directory('retention')]);
    $sinObservaciones = observacionesInformeEjecutado()->executed([], []);

    $texto = (string) file_get_contents(app(FileRetentionReportStore::class)->store($sinObservaciones));

    expect($texto)->not->toContain('Observaciones');
})->group('RF-PR-03');
