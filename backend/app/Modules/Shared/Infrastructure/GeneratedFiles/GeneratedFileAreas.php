<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\GeneratedFiles;

use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileClass;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileShape;
use Illuminate\Support\Facades\Config;

/**
 * **El catalogo de clases de fichero**: raiz, patron exacto de nombre y forma de
 * cada una (ADR-045, tabla de huerfanos; condiciones C1, C3 y C10).
 *
 * ## Por que los patrones viven aqui y no en cada escritor
 *
 * Porque lo que hay que garantizar es que **dos clases no se pisan**, y eso solo
 * se puede leer de un vistazo si estan juntas. El ZIP y su temporal comparten
 * raiz: `kronoqr-export-*.zip` y `kronoqr-export-*.zip.<sufijo>` son disjuntos
 * porque el sufijo de `ZipArchive` son exactamente seis alfanumericos —nunca
 * `zip`—, y la prueba unitaria del catalogo lo comprueba en las dos direcciones.
 *
 * Cada patron reproduce **el nombre que produce su escritor** y nada mas:
 * - ZIP: `DataExportManifest::fileName()`.
 * - Espacio de trabajo: `ZipDataExportArchiveWriter::begin()`, `.work-<uuid v7>`.
 * - Temporal de `ZipArchive`: el destino mas `.XXXXXX` (libzip lo crea con una
 *   plantilla de `mkstemp`).
 * - Informe en diferido: `FilesystemReportExportStorage`, `<uuid v7>/`.
 * - Exportacion legal: `registro-horario-<…>.csv`, por HTTP y por consola.
 * - Diagnostico: `DiagnosticsManifest::fileName()`.
 *
 * ## Añadir una clase
 *
 * Un caso en {@see GeneratedFileClass}, un metodo aqui con su raiz, su patron y
 * su forma, su raiz en {@see self::configuredRoots()} y una fila en el inventario
 * de `GeneratedFilesInventoryTest`. La conciliacion no cambia: la purga de su
 * modulo llama a `GeneratedFileHousekeeping::sweepOrphans()` con este area y su
 * regla de edad.
 */
final class GeneratedFileAreas
{
    /** `uuid` v7 en minusculas, que es como lo escriben `Str::uuid7()` y PostgreSQL. */
    public const string UUID_V7 = '[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    /**
     * Lo que puede llevar una version, un periodo o un aleatorio dentro de un
     * nombre de fichero. Sin `/` ni espacios: nunca escapa de la raiz.
     */
    private const string NAME_PART = '[A-Za-z0-9_.+-]+';

    /** No se instancia: es una tabla. */
    private function __construct() {}

    public static function dataExportArchives(string $root): GeneratedFileArea
    {
        return new GeneratedFileArea(
            GeneratedFileClass::DataExport,
            $root,
            '/^kronoqr-export-'.self::NAME_PART.'\.zip$/D',
            GeneratedFileShape::File,
        );
    }

    public static function dataExportArchiveTemporaries(string $root): GeneratedFileArea
    {
        return new GeneratedFileArea(
            GeneratedFileClass::DataExportWork,
            $root,
            // Seis alfanumericos y ni uno mas: con un sufijo libre, «.zip.zip»
            // casaria con las dos clases de esta raiz.
            '/^kronoqr-export-'.self::NAME_PART.'\.zip\.[A-Za-z0-9]{6}$/D',
            GeneratedFileShape::File,
        );
    }

    public static function dataExportWorkspaces(string $root): GeneratedFileArea
    {
        return new GeneratedFileArea(
            GeneratedFileClass::DataExportWork,
            $root,
            '/^\.work-'.self::UUID_V7.'$/D',
            GeneratedFileShape::Directory,
        );
    }

    public static function reportExports(string $root): GeneratedFileArea
    {
        return new GeneratedFileArea(
            GeneratedFileClass::ReportExport,
            $root,
            '/^'.self::UUID_V7.'$/D',
            GeneratedFileShape::Directory,
        );
    }

    public static function legalExportTemporaries(string $root): GeneratedFileArea
    {
        return new GeneratedFileArea(
            GeneratedFileClass::LegalExportTemporary,
            $root,
            '/^registro-horario-'.self::NAME_PART.'\.csv$/D',
            GeneratedFileShape::File,
        );
    }

    public static function legalExportConsole(string $root): GeneratedFileArea
    {
        return new GeneratedFileArea(
            GeneratedFileClass::LegalExportConsole,
            $root,
            '/^registro-horario-'.self::NAME_PART.'\.csv$/D',
            GeneratedFileShape::File,
        );
    }

    public static function diagnostics(string $root): GeneratedFileArea
    {
        return new GeneratedFileArea(
            GeneratedFileClass::Diagnostics,
            $root,
            '/^kronoqr-diagnostics-'.self::NAME_PART.'\.json$/D',
            GeneratedFileShape::File,
        );
    }

    /**
     * Las raices de clase de esta instalacion, por el nombre con el que las
     * conoce quien administra el servidor (la variable de `.env`, o la ruta fija
     * si no la hay).
     *
     * Es lo que comprueba `product:doctor`: ninguna puede coincidir con otra ni
     * contenerla, ni ser `storage/app` ni estar dentro de `BACKUP_PATH` (C3).
     *
     * @return array<string, string>
     */
    public static function configuredRoots(): array
    {
        return [
            'PRODUCT_DATA_EXPORT_PATH' => Config::string('product.data_export_path'),
            'REPORTING_EXPORT_PATH' => Config::string('reporting.export.path'),
            'storage/app/tmp/legal-exports' => Config::string('compliance.legal_export_temp_path'),
            'storage/app/legal-exports' => Config::string('compliance.legal_export_console_path'),
            'PRODUCT_DIAGNOSTICS_PATH' => Config::string('product.diagnostics_storage_path'),
            // La telemetria es un fichero; su raiz es el directorio que lo contiene.
            'TELEMETRY_STATE_PATH' => \dirname(Config::string('product.telemetry_state_path')),
        ];
    }
}
