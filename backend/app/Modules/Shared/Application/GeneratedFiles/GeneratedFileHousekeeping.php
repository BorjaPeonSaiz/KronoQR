<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\GeneratedFiles;

use App\Modules\Shared\Application\Port\GeneratedFileMetrics;
use App\Modules\Shared\Application\Port\GeneratedFileStore;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileClass;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileEntry;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileRemoval;
use App\Modules\Shared\Domain\ValueObject\RecordedFileLocation;
use DateTimeImmutable;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;

/**
 * La conciliacion de ficheros generados, **una sola vez** para todas las clases
 * (ADR-045 §a-§h; condiciones C1, C3, C5, C9 y C10).
 *
 * ## Por que vive en `Shared` y no en cada purga
 *
 * Porque el algoritmo es el mismo para la exportacion integra, los informes en
 * diferido, el temporal de la exportacion legal y el paquete de diagnostico:
 * listar un nivel de la raiz de la clase, quedarse con lo que casa con su
 * patron, medir la edad con `max(mtime, ctime)`, y borrar solo lo que ninguna
 * fila viva protege y ya supera su edad minima. Tres copias de eso serian tres
 * sitios donde el confinamiento podria olvidarse.
 *
 * Lo que **no** es comun es que protege una fila y que edad minima tiene cada
 * resto: eso lo decide el caso de uso de cada modulo, que es quien sabe que es
 * una fila viva, y lo pasa como funcion.
 *
 * ## Añadir una clase cuesta una entrada de catalogo
 *
 * Un caso en {@see GeneratedFileClass}, su raiz y su patron en el catalogo de
 * infraestructura, y una llamada a {@see self::sweepOrphans()} desde la purga
 * que le corresponda con su regla de edad. Nada de esta clase cambia.
 *
 * ## Lo que se registra y lo que no
 *
 * Huerfano borrado: log tecnico y metrica, **sin asiento** (§e). Algo que la
 * purga se nego a tocar: metrica aqui y log de aviso con el motivo en el
 * adaptador, que es quien lo conoce. En ningun caso un `uuid`, un
 * nombre de fichero ni una ruta (regla dura 21): solo la clase, del catalogo
 * cerrado. El asiento `*.file_missing` no se escribe aqui: lo escribe cada
 * modulo junto al cambio de estado de su fila, bajo el candado de la cadena.
 */
final readonly class GeneratedFileHousekeeping
{
    public function __construct(
        private GeneratedFileStore $store,
        private GeneratedFileMetrics $metrics,
        private LoggerInterface $logger,
        private SerializedLedgerWrite $serialized,
    ) {}

    /**
     * ¿Se puede conciliar esta clase? Solo si su raiz existe.
     *
     * Sin raiz no se concilia NADA de la clase: ni se marcan filas ni se publica
     * `*.file_missing`. Un `scheduler` levantado sin el volumen `app-storage`
     * —un override de compose, un montaje que fallo— veria desaparecidas todas
     * las exportaciones vigentes y las sellaria como una posible exfiltracion.
     * Se avisa y se sale; la siguiente pasada lo vuelve a intentar.
     */
    public function isAvailable(GeneratedFileArea $area): bool
    {
        if ($this->store->rootExists($area)) {
            return true;
        }

        $this->logger->warning('generated_files.root_unavailable', ['class' => $area->class->value]);

        return false;
    }

    /**
     * Marca una fila cuyo fichero desaparecio antes de caducar y, **solo si fue
     * esta llamada quien la marco**, publica el hecho y sube la metrica (C5).
     *
     * Es la regla comun de las dos purgas, en un solo sitio:
     *
     * - Todo ocurre con el candado de la cadena de `audit_log` tomado PRIMERO y
     *   la fila despues, el orden de toda escritura auditada
     *   ({@see SerializedLedgerWrite}).
     * - `$markPurged` tiene que ser condicional —`WHERE purged_at IS NULL`— y
     *   devolver si cambio la fila. Dos pasadas que leyeron la misma fila antes
     *   de marcarla (el planificador y una ejecucion a mano) se serializan en el
     *   candado; la segunda no marca nada, no publica nada y no pisa
     *   `purged_at` (regla dura 5). Un solo asiento y una sola subida.
     *
     * @param  callable(): bool  $markPurged
     * @param  callable(): void  $publish
     */
    public function purgeMissing(GeneratedFileClass $class, callable $markPurged, callable $publish): bool
    {
        $marked = $this->serialized->withChainLock(static function () use ($markPurged, $publish): bool {
            if (! $markPurged()) {
                return false;
            }

            $publish();

            return true;
        });

        if ($marked) {
            $this->fileMissing($class);
        }

        return $marked;
    }

    /**
     * Borra los restos de una clase que ya superan su edad minima y que ninguna
     * fila viva protege.
     *
     * @param  callable(GeneratedFileEntry): ?int  $minimumAgeSeconds  Edad minima de esa entrada, o
     *                                                                 `null` si una fila viva la
     *                                                                 protege y no se toca.
     * @return int Entradas borradas.
     */
    public function sweepOrphans(GeneratedFileArea $area, callable $minimumAgeSeconds, DateTimeImmutable $now): int
    {
        $span = SpanScope::start('kronoqr.product', 'generated_files.sweep', SpanKind::KIND_INTERNAL, [
            'class' => $area->class->value,
        ]);

        $removed = 0;
        $refused = 0;

        try {
            foreach ($this->store->entries($area) as $entry) {
                $minimum = $minimumAgeSeconds($entry);

                if ($minimum === null || ! $entry->isOlderThan($minimum, $now)) {
                    continue;
                }

                $outcome = $this->store->remove($area, $entry->name);

                if ($outcome === GeneratedFileRemoval::Removed) {
                    $this->metrics->orphanRemoved($area->class);
                    $removed++;
                } elseif ($outcome === GeneratedFileRemoval::Refused) {
                    $this->metrics->refused($area->class);
                    $refused++;
                } elseif ($outcome === GeneratedFileRemoval::Failed) {
                    // El log, con el motivo, lo escribe el adaptador.
                    $this->metrics->removeFailed($area->class);
                }
            }
        } finally {
            $span->end(['removed' => $removed, 'refused' => $refused]);
        }

        if ($removed > 0) {
            $this->logger->info('generated_files.orphans_removed', [
                'class' => $area->class->value,
                'count' => $removed,
                'trace_id' => $span->traceId(),
            ]);
        }

        return $removed;
    }

    /**
     * Borra el fichero que una fila dice tener, si cae dentro de su clase.
     *
     * Un `Refused` —la fila apunta fuera de su raiz— deja log y metrica y **no
     * borra nada**: puede ser una fila alterada que pretende que la purga se
     * lleve una copia de `BACKUP_PATH` (C3).
     */
    public function discardRecorded(GeneratedFileArea $area, string $recordedPath): GeneratedFileRemoval
    {
        $outcome = $this->store->discard($area, $recordedPath);

        if ($outcome === GeneratedFileRemoval::Refused) {
            $this->refusedRecorded($area->class);
        }

        if ($outcome === GeneratedFileRemoval::Failed) {
            // El log, con el motivo, lo escribe el adaptador. Quien llama NO
            // marca la fila: se reintenta en la siguiente pasada y la fila sigue
            // diciendo la verdad —el fichero esta ahi— (ADR-045, garantia).
            $this->metrics->removeFailed($area->class);
        }

        return $outcome;
    }

    /** Donde esta el fichero de una fila; avisa si la fila apunta fuera de su clase. */
    public function locateRecorded(GeneratedFileArea $area, string $recordedPath): RecordedFileLocation
    {
        $location = $this->store->locate($area, $recordedPath);

        if ($location === RecordedFileLocation::OutsideArea) {
            $this->refusedRecorded($area->class);
        }

        return $location;
    }

    /**
     * El fichero de una fila desaparecio antes de caducar (C5). Metrica y log
     * de aviso, sin `uuid`: el `uuid` va al asiento, que lo escribe el modulo.
     */
    public function fileMissing(GeneratedFileClass $class): void
    {
        $this->metrics->missing($class);

        $this->logger->warning('generated_files.file_missing', ['class' => $class->value]);
    }

    /**
     * Cuenta las entradas que ya superan una edad y publica la cifra.
     *
     * Para la clase que **no se borra sola** (la exportacion legal de consola,
     * §f): se avisa, nunca se toca.
     */
    public function reportOverdue(GeneratedFileArea $area, int $thresholdSeconds, DateTimeImmutable $now): int
    {
        $overdue = $this->countOlderThan($area, $thresholdSeconds, $now);

        $this->metrics->overdue($area->class, $overdue);

        return $overdue;
    }

    public function countOlderThan(GeneratedFileArea $area, int $thresholdSeconds, DateTimeImmutable $now): int
    {
        $count = 0;

        foreach ($this->store->entries($area) as $entry) {
            if ($entry->isOlderThan($thresholdSeconds, $now)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Solo la metrica: el log de aviso, con el MOTIVO (fuera de la raiz,
     * enlace, nombre ajeno), lo escribe el adaptador, que es quien lo sabe.
     */
    private function refusedRecorded(GeneratedFileClass $class): void
    {
        $this->metrics->refused($class);
    }
}
