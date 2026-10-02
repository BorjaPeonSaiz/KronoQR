<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Shared\Application\GeneratedFiles\GeneratedFileHousekeeping;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;

/**
 * La pasada horaria sobre los ficheros de la exportacion legal (RF-IN-05,
 * RL-19; ADR-045 §b y §f).
 *
 * ## Dos clases, dos tratamientos opuestos
 *
 * - **El temporal de la descarga HTTP** (`storage/app/tmp/legal-exports`) se
 *   borra siempre que supere su ventana: es un huerfano de una descarga
 *   abortada, con datos personales de la plantilla, y nadie lo va a reclamar.
 * - **La exportacion de consola** (`storage/app/legal-exports`) **no se borra
 *   nunca**: es la copia entregada a la Inspeccion, bajo custodia de quien la
 *   genero (runbook `requerimiento-inspeccion.md` §6). Lo que se hace es
 *   contarlas y publicar cuantas llevan mas de 30 dias en
 *   `generated_files_overdue{class="legal_export_console"}`, que es la serie
 *   que sostiene la alerta. Antes desaparecian de rebote en cada actualizacion;
 *   con el volumen persistente, sin este aviso vivirian para siempre.
 *
 * ## La edad, con el reloj inyectado
 *
 * Antes la decidia el comando con `time()` y `filemtime()`. Ahora es la de la
 * conciliacion compartida: `max(mtime, ctime)`, y `ctime` si `mtime` esta en el
 * futuro (C9). Un `touch -d` hacia atras ya no adelanta un borrado, y uno hacia
 * delante ya no lo impide.
 */
final readonly class SweepLegalExportFiles
{
    public function __construct(
        private GeneratedFileHousekeeping $files,
        private Clock $clock,
        /** `GeneratedFileAreas::legalExportTemporaries()`. */
        private GeneratedFileArea $temporaries,
        /** `GeneratedFileAreas::legalExportConsole()`. */
        private GeneratedFileArea $console,
        /** `compliance.legal_export_temp_retention_hours`, ya resuelto (regla dura 14). */
        private int $temporaryRetentionHours,
        /** `compliance.legal_export_console_warning_days`, ya resuelto. */
        private int $consoleWarningDays,
    ) {}

    /**
     * @return array{removed: int, overdue: int} Temporales borrados y exportaciones de consola
     *                                           que ya superan el plazo de aviso.
     */
    public function handle(): array
    {
        $now = $this->clock->now();
        $window = max(1, $this->temporaryRetentionHours) * 3600;

        $removed = $this->files->sweepOrphans(
            $this->temporaries,
            // Siempre huerfano: el temporal no tiene fila. Solo la ventana lo
            // separa de una descarga en curso sobre una red lenta.
            static fn (): int => $window,
            $now,
        );

        $overdue = $this->files->reportOverdue(
            $this->console,
            max(1, $this->consoleWarningDays) * 86400,
            $now,
        );

        return ['removed' => $removed, 'overdue' => $overdue];
    }
}
