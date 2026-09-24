<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Query;

/**
 * Lo que el borde recibe del cuadro de impacto: dos fechas, las dos opcionales
 * (**RF-IN-08**).
 *
 * ## Dos campos y una clase, y no dos argumentos
 *
 * Porque lo que hay que entender al leerlo no son las fechas sino **que pueden
 * llegar a medias**, y eso es lo que documenta esta clase en un solo sitio: `from`
 * sin `to`, `to` sin `from` o ninguna de las dos. La resolucion de cada caso la
 * hace {@see ReadAdoptionReport} y no el `FormRequest`, porque depende de la zona
 * del **centro** (ADR-040) y el borde no sabe donde esta el hotel: a las 00:30 del
 * 1 de abril en Madrid, el servidor en UTC sigue en marzo y «el mes pasado» seria
 * febrero.
 *
 * ## Aqui no hay alcance
 *
 * Al contrario que {@see ComplianceSummaryCriteria} y que el informe por periodo:
 * el cuadro es de la instalacion entera a proposito (regla dura 21) y su policy es
 * `{admin, rrhh}`. No hay nada que acotar, asi que no hay `ScopeGuard` que pasar.
 */
final readonly class AdoptionReportCriteria
{
    public function __construct(
        /** Fecha ISO `AAAA-MM-DD` ya validada por el `FormRequest`, o `null`. */
        public ?string $from = null,
        public ?string $to = null,
    ) {}
}
