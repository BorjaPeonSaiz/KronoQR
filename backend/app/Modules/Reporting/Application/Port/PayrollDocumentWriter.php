<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;

/**
 * Escribe la salida a **nomina** en una ruta del disco (**RF-IN-07**, ADR-017,
 * decision 5 de la ficha 3.9).
 *
 * ## Por que es un puerto aparte de {@see ReportExportDocumentWriter}
 *
 * Porque la nomina no es otro formato del mismo documento: es el **mismo
 * informe** —`PeriodReportReader` con `group_by = employee`, sin consulta
 * nueva— pasado por una plantilla que el cliente configura (`PAYROLL_EXPORT_*`
 * en los ajustes con ambito). Quien la resuelve necesita el proveedor de
 * plantillas y el catalogo de columnas, y nada de eso tiene que ver con escribir
 * un CSV de horas.
 *
 * Separarlo tiene ademas una consecuencia util: el ciclo de vida del informe en
 * diferido —la cola, el enlace caducable, la purga, el aviso, la auditoria— no
 * depende de la plantilla de nomina, y las dos mitades se pueden construir y
 * probar por separado.
 *
 * ## Quien lo implementa
 *
 * El adaptador vive en `Reporting\Infrastructure\Export` y compone el
 * `PayrollLayout` resuelto por `Shared\Application\Port\PayrollLayoutProvider`
 * con los escritores de nomina (CSV y XLSX). **Nunca PDF**: un programa de
 * nomina no importa un PDF, y {@see ReportExportKind::allows()}
 * ya lo deja fuera antes de llegar aqui.
 *
 * ## Mientras no haya adaptador
 *
 * El proveedor del modulo enlaza este puerto con un adaptador que **falla
 * ruidosamente** en lugar de escribir un fichero vacio o de caerse a la
 * disposicion del informe por periodo. Una exportacion de nomina que sale con
 * otras columnas de las configuradas es peor que una que no sale: la primera se
 * importa en la herramienta de nomina y nadie lo nota.
 */
interface PayrollDocumentWriter
{
    /** El nombre con el que se entrega el fichero. Sin datos personales (regla dura 21). */
    public function fileNameFor(ReportExport $export, PeriodReport $report): string;

    /**
     * Escribe el fichero de nomina y devuelve cuantas filas de datos lleva.
     *
     * @param  string  $path  Ruta absoluta, con el directorio ya creado.
     */
    public function write(ReportExport $export, PeriodReport $report, string $path): int;
}
