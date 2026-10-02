<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Shared\Application\GeneratedFiles\GeneratedFileHousekeeping;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;

/**
 * Barre los paquetes de diagnostico escritos por consola que superan su plazo
 * (**RF-PD-09**, **RL-19**, art. 5.1.e RGPD; ADR-045 §g, condicion C4).
 *
 * ## Por que existe una tarea que borra, si antes se decidio que no
 *
 * Hasta la 2.1.0 el paquete se borraba solo al generar el siguiente, y la
 * razon era buena: «una tarea que borrase ficheros del cliente por su cuenta
 * seria una sorpresa». Pero entonces cada contenedor tenia su propio
 * `storage/app` y cualquier actualizacion se lo llevaba por delante: el ultimo
 * paquete desaparecia de rebote. Con el volumen `app-storage` persistente ya no
 * desaparece, y un paquete pedido con `--with-personal-data` —una copia de la
 * plantilla y de los fichajes de un periodo— viviria para siempre si nadie
 * generara otro. RL-19 autoriza a generarlo para una incidencia, no a
 * conservarlo. La sorpresa la evita la guia, que lo dice, no la ausencia del
 * barrido.
 *
 * ## Un solo camino de borrado
 *
 * Lo usan la pasada horaria (`product:export-all --purge`) y el propio
 * `product:diagnostics` al arrancar. Los dos borran a traves de
 * {@see GeneratedFileHousekeeping}: un nivel de la raiz, patron exacto
 * `kronoqr-diagnostics-*.json`, sin seguir enlaces, y edad `max(mtime, ctime)`
 * (C3, C9). Lo que no casa con el patron no se toca.
 *
 * ## Sin fila que lo proteja
 *
 * El paquete no tiene fila en la base de datos: todo lo que casa con el patron
 * y supera el plazo es huerfano por definicion (tabla de huerfanos del ADR).
 */
final readonly class SweepExpiredDiagnosticsBundles
{
    public function __construct(
        private GeneratedFileHousekeeping $files,
        private Clock $clock,
        /** `GeneratedFileAreas::diagnostics()`: la raiz de los paquetes y su patron. */
        private GeneratedFileArea $bundles,
        /**
         * `PRODUCT_DIAGNOSTICS_RETENTION_DAYS`, ya resuelto por quien construye
         * (regla dura 14: el plazo llega resuelto, no se consulta aqui).
         */
        private int $retentionDays,
    ) {}

    /** @return int Paquetes borrados. */
    public function handle(): int
    {
        $minimumAge = max(1, $this->retentionDays) * 86400;

        return $this->files->sweepOrphans(
            $this->bundles,
            static fn (): int => $minimumAge,
            $this->clock->now(),
        );
    }
}
