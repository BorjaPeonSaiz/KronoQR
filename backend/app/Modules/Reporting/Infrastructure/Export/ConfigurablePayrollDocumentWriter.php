<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Application\Port\PayrollDocumentWriter;
use App\Modules\Reporting\Domain\Exception\ReportExportWriteFailed;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\PayrollLayoutProvider;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;

/**
 * La salida a nomina **en diferido**, con la plantilla que el cliente configuro
 * (**RF-IN-07**, ADR-017, decision 5 de la ficha 3.9).
 *
 * ## Es el mismo escritor que la descarga sincrona
 *
 * {@see PayrollCsvWriter} y {@see PayrollXlsxWriter} son los que usa
 * `GET /api/v1/reports/payroll-export`. Con dos implementaciones, el fichero que
 * RRHH descarga desde la pantalla y el que llega por el enlace del informe en
 * diferido podrian llevar columnas distintas — y una exportacion de nomina
 * equivocada no se descubre hasta la nomina siguiente.
 *
 * Lo unico que cambia entre los dos caminos es **el destino**: alli un temporal
 * que se transmite, aqui un fichero de `REPORTING_EXPORT_PATH` que vive siete
 * dias detras de un enlace de un solo uso.
 *
 * ## La plantilla se resuelve al GENERAR, no al pedir
 *
 * `PayrollLayoutProvider::forSite()` lee las seis claves `PAYROLL_EXPORT_*` de
 * los ajustes con ambito en el momento en que el trabajo corre. Es una decision
 * con consecuencia: si alguien cambia el delimitador entre la peticion y la
 * generacion, el fichero sale con el nuevo.
 *
 * Y es lo correcto, al contrario que el **alcance**, que si viaja congelado en
 * la fila. La diferencia es de naturaleza: el alcance es una **autorizacion**
 * —y tiene que ser la que se concedio, porque es la que quedo en `audit_log`—,
 * mientras que la plantilla es **presentacion** (`SettingImpact::PRESENTATION`):
 * no cambia ni un minuto trabajado, solo como se escriben. Congelarla obligaria
 * a guardar una copia de los seis ajustes en cada fila para que el fichero
 * saliera con una configuracion que ya nadie tiene.
 *
 * ## Sin centro no hay nomina
 *
 * La plantilla es del centro (ADR-040), asi que antes de la puesta en marcha no
 * hay nada que resolver. No deberia poder ocurrir —`GeneratePeriodReport` ya
 * falla antes por la misma razon, y sin centro no hay zona horaria en la que
 * expresar el informe—, y se comprueba igualmente: un fichero de nomina con la
 * disposicion por omision, generado porque el centro no se pudo leer, es
 * exactamente el fichero equivocado que nadie mira.
 */
final readonly class ConfigurablePayrollDocumentWriter implements PayrollDocumentWriter
{
    public function __construct(
        private PayrollLayoutProvider $layouts,
        private InstallationSiteProvider $installation,
        private PayrollCsvWriter $csv,
        private PayrollXlsxWriter $xlsx,
    ) {}

    public function fileNameFor(ReportExport $export, PeriodReport $report): string
    {
        /*
         * `nomina` y no `horas`, igual que en la descarga sincrona: en una carpeta
         * de descargas los dos ficheros llevan las mismas fechas y contenidos muy
         * distintos, y el que se importa en la herramienta de nomina no puede ser
         * el que no es.
         *
         * **Sin ningun nombre de persona** (regla dura 21): el periodo y la
         * extension.
         */
        return 'kronoqr-nomina-'.$report->range->isoFrom().'_'.$report->range->isoTo().'.'.$export->format;
    }

    public function write(ReportExport $export, PeriodReport $report, string $path): int
    {
        $site = $this->installation->installationSite();

        if ($site === null) {
            throw new InstallationSiteMissing;
        }

        $layout = $this->layouts->forSite($site->id);

        return match ($export->format) {
            'csv' => $this->csv->write($report, $layout, $path),
            'xlsx' => $this->xlsx->write($report, $layout, $path),
            // Inalcanzable por los tres sitios que ya lo cierran —el catalogo del
            // dominio, el `FormRequest` y el `CHECK` de la migracion—, y aun asi
            // declarado: el dia que alguien añada un formato, esto rompe donde se
            // ve en lugar de escribir un CSV en silencio con otra extension.
            default => throw ReportExportWriteFailed::of(
                'la salida a nomina no se entrega en ese formato',
            ),
        };
    }
}
