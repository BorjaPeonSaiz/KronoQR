<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\RetentionMode;
use App\Modules\Compliance\Domain\ValueObject\RetentionPolicySnapshot;
use App\Modules\Compliance\Domain\ValueObject\RetentionReport;
use App\Modules\Compliance\Infrastructure\Retention\FileRetentionReportStore;
use Tests\Support\Shared\GeneratedFilesSandbox;

/*
 * El informe de retencion nace con modo `0640` y nunca pisa otro (ADR-045,
 * hallazgo de la instalacion real en docker-in-docker).
 *
 * Antes se escribia con `file_put_contents` y su modo era el de la umask del
 * proceso: `0644` normalmente, `0666` con un `docker compose exec` de umask
 * `0000`. Vive en `BACKUP_PATH/reports/retention`, junto a las copias: `0640`
 * es el modo que fija el ADR y el que deja el rescate de `update.sh`.
 */

afterEach(function (): void {
    GeneratedFilesSandbox::cleanUp();
});

function informeDeRetencionDePrueba(): RetentionReport
{
    return new RetentionReport(
        mode: RetentionMode::Simulation,
        generatedAt: new DateTimeImmutable('2026-10-02T05:10:00Z'),
        policy: new RetentionPolicySnapshot(4, 90, 90, 1),
        workRecordCutoff: new DateTimeImmutable('2022-01-01T00:00:00Z'),
        auditPartitionYears: [],
        shortCycleCutoffs: [],
        tallies: [],
    );
}

it('crea el informe con 0640 aunque la umask del proceso sea 0000', function (): void {
    config(['compliance.retention.report_path' => GeneratedFilesSandbox::directory('retention')]);
    $anterior = umask(0);

    try {
        $ruta = (new FileRetentionReportStore)->store(informeDeRetencionDePrueba());
    } finally {
        umask($anterior);
    }

    clearstatcache();

    expect(fileperms($ruta) & 0o777)->toBe(0o640)
        ->and((string) file_get_contents($ruta))->not->toBe('');
})->group('RF-PR-03', 'RL-04');

it('no pisa un informe que ya existe con el mismo nombre', function (): void {
    // Dos pasadas en el mismo segundo, o un informe rescatado de la 2.1.0.
    $directorio = GeneratedFilesSandbox::directory('retention');
    config(['compliance.retention.report_path' => $directorio]);
    $existente = GeneratedFilesSandbox::file($directorio.'/retencion-propuesta-20261002-051000.txt', 'el de antes');

    $ruta = (new FileRetentionReportStore)->store(informeDeRetencionDePrueba());

    expect($ruta)->not->toBe($existente)
        ->and((string) file_get_contents($existente))->toBe('el de antes')
        ->and(basename($ruta))->toBe('retencion-propuesta-20261002-051000-1.txt')
        ->and(fileperms($ruta) & 0o777)->toBe(0o640);
})->group('RF-PR-03', 'RL-04');
