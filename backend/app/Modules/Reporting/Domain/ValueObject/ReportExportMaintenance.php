<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Lo que hizo la pasada diaria de mantenimiento de los informes en diferido
 * (**RF-IN-06**, regla dura 5, ADR-045).
 *
 * ## Cuatro cifras y no una, porque son cuatro trabajos distintos
 *
 * - `purged` — ficheros borrados por caducar. Es la promesa de retencion
 *   cumpliendose: un fichero con las horas de la plantilla no puede quedarse en
 *   el disco porque nadie se acuerde de borrarlo.
 * - `released` — filas que llevaban demasiado tiempo `pending` o `running` y
 *   pasan a `failed` con motivo `stale`. Es lo que devuelve a una persona la
 *   posibilidad de pedir otro informe despues de que el servidor se parase a
 *   mitad.
 * - `orphans` — directorios `<uuid>/` que ninguna fila viva menciona.
 * - `missing` — filas cuyo fichero desaparecio **antes** de caducar: un evento de
 *   seguridad que deja `report_export.file_missing` en `audit_log`.
 *
 * Separarlas es lo que permite leer la salida del comando y saber si lo que ha
 * pasado es normal (ficheros que caducan) o si hay algo que mirar (trabajos que
 * no terminan, ficheros que desaparecen).
 */
final readonly class ReportExportMaintenance
{
    public function __construct(
        public int $purged,
        public int $released,
        /**
         * Directorios borrados que **ninguna fila viva mencionaba**: ficheros a
         * medias de una generacion que murio sin poder cerrarse, o restos de una
         * restauracion. Solo al superar su edad minima (ADR-045 §b).
         */
        public int $orphans = 0,
        /** Filas `completed` cuyo fichero desaparecio antes de su `expires_at`. */
        public int $missing = 0,
    ) {}
}
