<?php

declare(strict_types=1);

namespace App\Support\Queue;

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Queue\FailoverQueue;

/**
 * Una cola `failover` que **espera al COMMIT antes de elegir conexion** (CH1).
 *
 * La `FailoverQueue` del framework prueba cada conexion dentro de un `try`, pero
 * si el trabajo pide ejecutarse despues del COMMIT (`afterCommit`), la conexion
 * de verdad no encola: **registra** el envio para cuando confirme la
 * transaccion y devuelve sin error. El `push` a Redis ocurre despues, ya fuera
 * del `try`, y su `RedisException` sale por el `transaction()` del caso de uso
 * con el fichaje ya confirmado. Es lo que hacian la difusion al panel en vivo y
 * la metrica de minutos trabajados con Redis caido: el quiosco recibia un `500`
 * por un fichaje que ya estaba registrado.
 *
 * Aqui el aplazamiento lo hace esta cola, **antes** de elegir conexion: cuando
 * la transaccion confirma, se intenta la conexion principal —ya sin transaccion
 * abierta, asi que encola en el acto— y, si falla, la siguiente. Una transaccion
 * que revierte no encola nada, igual que antes.
 */
final class AfterCommitFailoverQueue extends FailoverQueue
{
    /**
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     */
    #[\Override]
    public function push($job, $data = '', $queue = null): mixed
    {
        if ($this->shouldDispatchAfterCommit($job) && $this->container->bound('db.transactions')) {
            /** @var DatabaseTransactionsManager $transactions */
            $transactions = $this->container->make('db.transactions');

            $transactions->addCallback(fn (): mixed => parent::push($job, $data, $queue));

            return null;
        }

        return parent::push($job, $data, $queue);
    }
}
