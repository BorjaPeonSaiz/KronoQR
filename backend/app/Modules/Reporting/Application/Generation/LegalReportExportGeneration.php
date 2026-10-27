<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Generation;

use App\Modules\Reporting\Application\Port\ReportExportStorage;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStage;
use App\Modules\Shared\Application\Port\LegalRecordFileWriter;

/**
 * La exportacion para la Inspeccion en diferido (**RF-IN-05**, RL-06).
 *
 * ## El mismo fichero que la descarga sincrona
 *
 * Lo escribe `Compliance` con su origen y su escritor, por el puerto de
 * `Shared` {@see LegalRecordFileWriter}: mismas filas, mismo orden, misma
 * cabecera de criterios y el mismo nombre. Existe porque la plantilla entera
 * de un periodo largo no cabe en los 60 s de nginx y PHP-FPM, y un
 * requerimiento de la Inspeccion no puede terminar en un error generico.
 *
 * ## Sin alcance por departamento, y es a proposito
 *
 * Como la sincrona: o la plantilla completa, o una persona (RF-IN-05). La
 * exportacion legal no la acota el alcance del rol —el auditor y RRHH la
 * piden entera— y por eso aqui no se lee `scope`.
 *
 * ## Con porcentaje de verdad
 *
 * Es la unica que lo tiene: `Compliance` cuenta las filas antes de abrir el
 * cursor y avisa cada 500. Ese aviso es tambien el punto de cancelacion.
 *
 * ## Su asiento es el suyo
 *
 * `legal_export.generated`, con las mismas claves que la sincrona, viaja en
 * `legalExport` y lo escribe el cierre a nombre de quien la pidio. No hay
 * `personal_data.accessed` aparte, como tampoco lo hay en la sincrona: el
 * asiento legal ya dice quien, que periodo, que alcance y cuanto.
 */
final readonly class LegalReportExportGeneration implements ReportExportGeneration
{
    public function __construct(
        private LegalRecordFileWriter $files,
        private ReportExportStorage $storage,
    ) {}

    public function generate(ReportExportRun $run): GeneratedReportFile
    {
        $export = $run->export;
        $parameters = $export->parameters;

        $run->progress->enter(ReportExportStage::Reading);

        $path = $run->claim($this->storage->pathFor(
            $export->uuid,
            $this->files->fileNameFor($parameters->from, $parameters->to),
        ));

        $file = $this->files->write(
            $parameters->from,
            $parameters->to,
            $parameters->employeeUuid,
            $path,
            static function (int $done, int $expected) use ($run): void {
                $run->progress->rows($done, $expected);
            },
        );

        return new GeneratedReportFile(
            fileName: $file->fileName,
            path: $path,
            rowCount: $file->rows(),
            // La cabecera de criterios va DENTRO del fichero (RL-06); repetirla
            // aqui seria otra traduccion que mantener en paralelo.
            criteria: [],
            employeeUuids: $file->employeeUuid === null ? [] : [$file->employeeUuid],
            legalExport: $file->auditFacts(),
        );
    }
}
