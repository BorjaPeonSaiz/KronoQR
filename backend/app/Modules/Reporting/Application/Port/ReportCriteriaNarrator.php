<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\ReportCriterion;

/**
 * Traduce los criterios de inclusion de un informe a lineas legibles
 * (**RF-IN-06**, decision 1 de la ficha 3.9).
 *
 * ## Por que los criterios se guardan traducidos en la fila
 *
 * Porque el fichero de **nomina no los lleva dentro** (decision 5): una fila de
 * comentario al principio del CSV rompe la importacion de la herramienta de
 * nomina. Y un informe de horas sin sus criterios es una tabla de numeros que
 * cada persona interpreta a su manera — el paso 1 de `/informe-nuevo` lo pide
 * por escrito: «visibles para quien lo lee».
 *
 * Asi que viajan en la columna `criteria` de `report_exports`, salen en la API y
 * los enseña la pantalla junto a la descarga. Para eso tienen que estar
 * **traducidos**: {@see ReportCriterion}
 * es una clave con sustituciones, y traducir es de la capa de presentacion.
 *
 * ## En el idioma de la INSTALACION, no en el de quien pide
 *
 * Mismo criterio que `locale.installation` en la descarga sincrona (regla dura
 * 13): un documento se entrega a un tercero y lo abre un programa cuyo idioma no
 * es el del navegador que lo pidio. El **correo** de aviso si va en el idioma de
 * la cuenta, porque eso lo lee una persona concreta.
 *
 * ## Por que un puerto
 *
 * `Application` no alcanza `Infrastructure` (Deptrac) y el caso de uso no debe
 * saber que existe un catalogo de traducciones. El adaptador vive junto a los
 * escritores, que ya usan exactamente las mismas lineas para la cabecera del CSV
 * y para la hoja «Criterios» del XLSX: una sola fuente, o el fichero y la
 * pantalla dirian cosas distintas sobre el mismo informe.
 */
interface ReportCriteriaNarrator
{
    /**
     * @return list<string>
     */
    public function linesFor(PeriodReport $report): array;
}
