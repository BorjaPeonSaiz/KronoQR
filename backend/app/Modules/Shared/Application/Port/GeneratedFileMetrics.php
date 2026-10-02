<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\GeneratedFileClass;

/**
 * Las cuatro series de los ficheros generados (ADR-045 §e, §f, §h; C5, C10).
 *
 * **Una sola etiqueta, `class`, del catalogo cerrado** {@see GeneratedFileClass}.
 * Nunca `uuid`, nombre ni ruta (regla dura 21): por eso la firma solo admite el
 * enumerado y no una cadena.
 *
 * Medir no puede romper una purga: el adaptador se traga sus propios fallos.
 */
interface GeneratedFileMetrics
{
    /** `generated_files_orphans_removed_total{class}`: un resto sin fila viva borrado. */
    public function orphanRemoved(GeneratedFileClass $class): void;

    /**
     * `generated_files_refused_total{class}`: algo que la purga se nego a tocar
     * por confinamiento (fuera de la raiz, enlace, subdirectorio).
     */
    public function refused(GeneratedFileClass $class): void;

    /**
     * `generated_files_missing_total{class}`: una fila `completed` cuyo fichero
     * desaparecio **antes** de su `expires_at`. Evento de seguridad (C5).
     */
    public function missing(GeneratedFileClass $class): void;

    /**
     * `generated_files_remove_failed_total{class}`: el sistema de ficheros nego
     * un borrado que tocaba (permisos, solo lectura). El fichero sigue en el
     * disco y la fila no se marca: se reintenta en la siguiente pasada.
     */
    public function removeFailed(GeneratedFileClass $class): void;

    /**
     * `generated_files_overdue{class}`: cuantos ficheros siguen en el disco mas
     * alla de su plazo de aviso. Hoy solo `legal_export_console` (> 30 dias).
     */
    public function overdue(GeneratedFileClass $class, int $count): void;
}
