<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\ErrorEventQuery;
use App\Modules\Product\Application\Port\ErrorEventRepository;
use App\Modules\Product\Domain\ValueObject\ErrorEventStatusFilter;
use App\Modules\Shared\Application\Port\Clock;
use DateInterval;
use DateTimeImmutable;

/**
 * `php artisan product:errors:prune` — la purga del historico tecnico
 * (RF-PD-15, RL-11, decision 10 de la ficha 5.12).
 *
 * ## Esto NO es la purga del registro legal
 *
 * Es la del **ciclo corto**: 90 dias de diagnostico tecnico
 * (`ERROR_HISTORY_RETENTION_DAYS`), la misma cifra que el log tecnico y por el
 * mismo motivo (doc 02 §8.2.1). Nada que ver con RF-PR-03, que purga jornadas y
 * asientos a los cuatro anos del perfil de cumplimiento, exige un token de
 * confirmacion, deja informe y toca `audit_log`.
 *
 * De ahi las tres diferencias que importan y que estan aqui a proposito:
 *
 * - **Sin confirmacion.** Corre sola cada noche. Pedir un token para borrar un
 *   error de hace tres meses convertiria la purga en algo que nadie ejecuta.
 * - **Sin asiento.** Ver {@see ResolveErrorEvent}: esto no es evidencia legal.
 * - **Borra de verdad** (excepcion a la regla dura 5, acotada a esta tabla). Es
 *   diagnostico tecnico, no un registro con obligacion de conservacion.
 *
 * `compliance:apply-retention` sigue informando del ciclo corto como hasta
 * ahora: su adaptador `DatabaseErrorHistoryArchive` **cuenta y purga la misma
 * tabla por la misma columna**, y ese es el punto de encuentro. Que haya dos
 * caminos no es una duplicidad: aquel es el informe semanal que se archiva —lo
 * mira una persona— y este es la pasada diaria que mantiene la tabla en su sitio
 * sin que nadie tenga que acordarse.
 *
 * ## El corte es por `last_seen_at`
 *
 * Un grupo que sigue ocurriendo cada dia **no vence** porque su primera
 * aparicion sea antigua. Lo que se conserva 90 dias es un grupo vivo.
 *
 * ## La simulacion cuenta con la misma consulta que borra
 *
 * `--dry-run` no estima: pregunta cuantos grupos casan con el mismo predicado
 * que usaria el borrado. Un ensayo que dijera una cifra y una ejecucion que
 * borrara otra seria peor que no tener ensayo.
 */
final readonly class PruneErrorEvents
{
    public function __construct(
        private ErrorEventRepository $errors,
        private Clock $clock,
        private int $retentionDays,
        private int $batchSize,
    ) {}

    /** El instante antes del cual un grupo vence. */
    public function cutoff(): DateTimeImmutable
    {
        return $this->clock->now()->sub(new DateInterval('P'.max(1, $this->retentionDays).'D'));
    }

    public function retentionDays(): int
    {
        return max(1, $this->retentionDays);
    }

    /**
     * Cuantos grupos se irian, sin tocar nada.
     *
     * Se cuenta con `page()` y `perPage: 1` porque el `total` que devuelve es el
     * de la consulta completa: la pagina no se usa, el recuento si. Evita anadir
     * al puerto un metodo mas que solo tendria un consumidor.
     *
     * **EL PREDICADO ES EXACTAMENTE EL DEL BORRADO.** El filtro `to` de la
     * consulta es inclusivo (`<=`) y el borrado usa `<`, asi que aqui se
     * pregunta por el microsegundo anterior al corte: con precision de
     * microsegundo —la de la columna— las dos expresiones seleccionan las mismas
     * filas. Un ensayo que dijera una cifra y una ejecucion que se llevara otra
     * seria peor que no tener ensayo.
     */
    public function pending(): int
    {
        return $this->errors->page(new ErrorEventQuery(
            status: ErrorEventStatusFilter::All,
            to: $this->cutoff()->modify('-1 microsecond'),
            page: 1,
            perPage: 1,
        ))->total;
    }

    /** Borra, por lotes, y devuelve cuantos se fueron. */
    public function handle(): int
    {
        return $this->errors->pruneOlderThan($this->cutoff(), $this->batchSize);
    }
}
