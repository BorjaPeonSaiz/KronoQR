<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Lo que hizo la pasada diaria de mantenimiento de los informes en diferido
 * (**RF-IN-06**, regla dura 5).
 *
 * ## Dos cifras y no una, porque son dos trabajos distintos
 *
 * - `purged` — ficheros borrados por caducar. Es la promesa de retencion
 *   cumpliendose: un fichero con las horas de la plantilla no puede quedarse en
 *   el disco porque nadie se acuerde de borrarlo.
 * - `released` — filas que llevaban demasiado tiempo `pending` o `running` y
 *   pasan a `failed` con motivo `stale`. Es lo que devuelve a una persona la
 *   posibilidad de pedir otro informe despues de que el servidor se parase a
 *   mitad.
 *
 * Separarlas es lo que permite leer la salida del comando y saber si lo que ha
 * pasado es normal (ficheros que caducan) o si hay algo que mirar (trabajos que
 * no terminan).
 */
final readonly class ReportExportMaintenance
{
    public function __construct(
        public int $purged,
        public int $released,
        /**
         * Directorios borrados que **ninguna fila mencionaba**: ficheros a medias
         * de una generacion que murio sin poder cerrarse.
         *
         * Va aparte de `purged` porque significa otra cosa: purgar es la retencion
         * cumpliendose y esto es basura que no deberia haber estado ahi. Si esta
         * cifra deja de ser cero un dia si y otro tambien, lo que hay que mirar es
         * por que se mueren los trabajos.
         */
        public int $orphans = 0,
    ) {}
}
