<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportParameters;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Filas de `report_exports` para las pruebas de feature e integracion
 * (**RF-IN-06**).
 *
 * ## Por que el directorio es temporal y se limpia
 *
 * Lo que aqui se escribe son ficheros con horas de empleados de prueba, pero el
 * habito es el mismo que con la exportacion integra y el paquete de
 * diagnostico: una suite que deja ficheros sueltos en `storage/app/reports`
 * acaba con un disco lleno en la maquina de alguien y, lo que es peor, con
 * pruebas que pasan porque encuentran el fichero de la ejecucion anterior.
 */
final class ReportExports
{
    /** Cambia `REPORTING_EXPORT_PATH` a un directorio temporal y lo devuelve. */
    public static function useTemporaryPath(): string
    {
        $directory = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'kronoqr-report-exports-'.bin2hex(random_bytes(6));

        Config::set('reporting.export.path', $directory);

        return $directory;
    }

    public static function cleanUpTemporaryPath(): void
    {
        $directory = Config::string('reporting.export.path');

        if (! str_contains($directory, 'kronoqr-report-exports-') || ! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.\DIRECTORY_SEPARATOR.'*') ?: [] as $child) {
            foreach (glob($child.\DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($child);
        }

        @rmdir($directory);
    }

    /**
     * Una exportacion `pending` recien pedida por esa cuenta.
     *
     * Pasa por el repositorio de verdad —y por tanto por el indice unico parcial
     * y por los `CHECK`— en lugar de insertar a mano: una fila que la base de
     * datos no admitiria no sirve para probar nada.
     */
    public static function pendingFor(
        int $userId,
        ReportExportKind $kind = ReportExportKind::Period,
        string $format = 'csv',
        string $from = '2026-03-01',
        string $to = '2026-03-31',
        ?AccessScope $scope = null,
    ): ReportExport {
        return app(ReportExportRepository::class)->create(
            uuid: Str::uuid7()->toString(),
            kind: $kind,
            format: $format,
            parameters: new ReportExportParameters(
                from: $from,
                to: $to,
                granularity: ReportGranularity::Day,
                grouping: ReportGrouping::Employee,
                includeOpenShifts: false,
                departmentId: null,
                employeeUuid: null,
            ),
            scope: $scope ?? AccessScope::unrestricted(),
            criteria: [],
            requestedByUserId: $userId,
            requestedAt: self::at('2026-03-08T09:00:00+00:00'),
        );
    }

    /**
     * Una exportacion `completed` **con su fichero de verdad en el disco**.
     *
     * El fichero importa: la descarga comprueba que sigue estando, y una fila
     * `completed` que apunta a un fichero inexistente es un caso real —alguien
     * vacio el directorio para hacer sitio— que tiene que responder `404` y no
     * reventar.
     */
    public static function completedFor(
        int $userId,
        string $contents = "uuid;horas\n1;08:00\n",
        ?DateTimeImmutable $expiresAt = null,
    ): ReportExport {
        $export = self::pendingFor($userId)->start(self::at('2026-03-08T09:00:05+00:00'));

        app(ReportExportRepository::class)->save($export);

        $directory = Config::string('reporting.export.path').\DIRECTORY_SEPARATOR.$export->uuid;

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $fileName = 'kronoqr-horas-2026-03-01_2026-03-31.csv';
        $path = $directory.\DIRECTORY_SEPARATOR.$fileName;

        file_put_contents($path, $contents);

        $completed = $export->complete(
            completedAt: self::at('2026-03-08T09:02:00+00:00'),
            filePath: $path,
            fileName: $fileName,
            sizeBytes: \strlen($contents),
            sha256: hash('sha256', $contents),
            rowCount: 1,
            criteria: ['Los totales salen del registro horario ya consolidado.'],
            // **Vigente respecto al reloj real**, no una fecha fija: la emision
            // del enlace comprueba que el fichero no haya caducado, asi que un
            // `expires_at` en el pasado convertiria todas las pruebas de descarga
            // en «no hay enlace» sin decir por que.
            expiresAt: $expiresAt ?? app(Clock::class)->now()->modify('+7 days'),
        );

        app(ReportExportRepository::class)->save($completed);

        return $completed;
    }

    public static function find(string $uuid): ReportExport
    {
        return app(ReportExportRepository::class)->findByUuid($uuid)
            ?? throw new \RuntimeException('No existe la exportacion de prueba: '.$uuid);
    }

    public static function at(string $wallClock): DateTimeImmutable
    {
        return new DateTimeImmutable($wallClock, new DateTimeZone('UTC'));
    }

    /** No se instancia: es una fabrica de datos de prueba. */
    private function __construct() {}
}
