<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\DownloadDataExportHandler;
use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\UseCase\DownloadReportExport;
use App\Modules\Reporting\Domain\Exception\ReportExportLinkUnavailable;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\ReportExports;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las dos descargas toman el candado de la cadena de `audit_log` ANTES que la
 * fila de la exportacion (ADR-010, ADR-045 §d, condicion C5).
 *
 * ## Por que importa el orden
 *
 * La purga, cuando un fichero desaparece antes de caducar, toma el candado de
 * la cadena y despues la fila, para marcarla `purged` y sellar
 * `*.file_missing` en la misma transaccion. Las descargas lo hacian al reves
 * —la fila (`UPDATE` o `FOR UPDATE`) y despues el asiento—: con el fichero
 * desapareciendo entre la comprobacion de la descarga y su asiento, las dos
 * transacciones se esperaban mutuamente y PostgreSQL mataba una.
 *
 * ## Por que se prueba el orden y no la carrera
 *
 * La ventana es de milisegundos y no se puede provocar de forma determinista
 * sin instrumentar el propio caso de uso. Lo que se fija es la causa: en la
 * secuencia de sentencias de la descarga, `pg_advisory_xact_lock` va antes que
 * la primera sentencia que bloquea la fila. Con un orden global unico no hay
 * ciclo posible (`SerializedLedgerWrite`).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    DataExports::useTemporaryPath();
    ReportExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
    ReportExports::cleanUpTemporaryPath();
});

/**
 * Las sentencias que ejecuta `$work`, en orden.
 *
 * @return list<string>
 */
function sentenciasDeLaDescarga(callable $work): array
{
    $sentencias = [];

    DB::listen(static function (QueryExecuted $query) use (&$sentencias): void {
        $sentencias[] = strtolower($query->sql);
    });

    $work();

    return $sentencias;
}

/**
 * Posicion de la primera sentencia que cumple `$predicado`, o -1.
 *
 * @param  list<string>  $sentencias
 * @param  callable(string): bool  $predicado
 */
function posicionDe(array $sentencias, callable $predicado): int
{
    foreach ($sentencias as $indice => $sentencia) {
        if ($predicado($sentencia)) {
            return $indice;
        }
    }

    return -1;
}

it('la descarga de la exportacion integra toma la cadena antes que la fila', function (): void {
    $exportacion = DataExports::completed();

    $sentencias = sentenciasDeLaDescarga(static fn () => app(DownloadDataExportHandler::class)->handle(
        $exportacion->uuid,
        static fn (string $path): bool => is_file($path),
        null,
    ));

    $candado = posicionDe($sentencias, static fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'));
    $fila = posicionDe($sentencias, static fn (string $sql): bool => str_starts_with($sql, 'update "data_exports"'));

    expect($candado)->toBeGreaterThanOrEqual(0)
        ->and($fila)->toBeGreaterThan($candado)
        ->and(DB::table('audit_log')->where('action', 'data_export.downloaded')->count())->toBe(1);
})->group('RF-PD-14', 'RL-15');

it('la descarga de un informe toma la cadena antes que el FOR UPDATE de la fila', function (): void {
    $informe = ReportExports::completedFor(ManagementUsers::withRole(UserRole::RRHH)->id);
    $token = 'token-de-prueba-'.bin2hex(random_bytes(8));
    app(ReportExportRepository::class)->save(
        $informe->issueDownloadToken(hash('sha256', $token), app(Clock::class)->now()->modify('+15 minutes')),
    );

    $sentencias = sentenciasDeLaDescarga(static fn () => app(DownloadReportExport::class)->handle($informe->uuid, $token));

    $candado = posicionDe($sentencias, static fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'));
    $fila = posicionDe($sentencias, static fn (string $sql): bool => str_contains($sql, 'for update'));

    expect($candado)->toBeGreaterThanOrEqual(0)
        ->and($fila)->toBeGreaterThan($candado)
        ->and(DB::table('audit_log')->where('action', 'report_export.downloaded')->count())->toBe(1);
})->group('RF-IN-06', 'RL-15');

it('el enlace del informe sigue siendo de un solo uso con el nuevo orden', function (): void {
    // ADR-041: el `FOR UPDATE` sigue dentro de la transaccion, ahora detras del
    // candado de la cadena. La segunda descarga con el mismo token no entrega.
    $informe = ReportExports::completedFor(ManagementUsers::withRole(UserRole::RRHH)->id);
    $token = 'token-de-prueba-'.bin2hex(random_bytes(8));
    app(ReportExportRepository::class)->save(
        $informe->issueDownloadToken(hash('sha256', $token), app(Clock::class)->now()->modify('+15 minutes')),
    );

    $primera = app(DownloadReportExport::class)->handle($informe->uuid, $token);

    expect($primera)->not->toBeNull()
        ->and(static fn () => app(DownloadReportExport::class)->handle($informe->uuid, $token))
        ->toThrow(ReportExportLinkUnavailable::class);
})->group('RF-IN-06');
