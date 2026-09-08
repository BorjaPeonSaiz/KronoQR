<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Infrastructure\Diagnostics\ServiceInspector;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Sondas `queue.*` de `product:doctor` (RF-PD-13).
 *
 * ## Por que la cola merece tres comprobaciones
 *
 * Porque su fallo es **silencioso**. El sistema sigue aceptando fichajes, las
 * pantallas responden y nadie nota nada; lo que deja de ocurrir son los avisos,
 * los informes programados y la reconciliacion nocturna. El cliente lo descubre
 * dos semanas despues, cuando echa en falta algo. `doctor` es lo unico que lo
 * puede ver antes.
 *
 * ## `queue.worker` avisa cuando NO puede saberlo
 *
 * No hay forma fiable de preguntar «¿hay un trabajador vivo?» sin Horizon, y
 * este producto no lo lleva. Lo que si se puede medir es si algo se esta
 * consumiendo: con trabajos encolados y ninguno reservado, o nadie los coge o no
 * hay nadie. En ese caso sale `warning` con **como comprobarlo** —el comando
 * exacto— en lugar de un veredicto inventado. Un `ok` optimista aqui seria peor
 * que no comprobar nada.
 */
final readonly class QueueProbe implements DoctorProbe
{
    public function __construct(
        private ServiceInspector $services,
        private Clock $clock,
    ) {}

    public function family(): string
    {
        return 'queue';
    }

    public function run(): array
    {
        $redis = $this->services->redis();

        if ($redis['reachable'] !== true) {
            // Sin Redis no hay cola que medir, y ademas no hay cache ni sesion:
            // se dice una vez, con lo que hay que hacer, y no tres.
            return [DoctorFinding::failure('queue.redis', details: $redis)];
        }

        $queue = $this->services->queue();

        return [
            DoctorFinding::ok('queue.redis', $redis),
            $this->backlog($queue),
            $this->worker($queue),
        ];
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    private function backlog(array $queue): DoctorFinding
    {
        $size = $queue['size'] ?? null;

        if (! is_int($size)) {
            return DoctorFinding::warning('queue.backlog', 'unknown', details: $queue);
        }

        if ($size >= ServiceInspector::QUEUE_BACKLOG_FAILURE) {
            return DoctorFinding::failure('queue.backlog', params: ['count' => $size], details: $queue);
        }

        if ($size >= ServiceInspector::QUEUE_BACKLOG_WARNING) {
            return DoctorFinding::warning('queue.backlog', params: ['count' => $size], details: $queue);
        }

        return DoctorFinding::ok('queue.backlog', $queue, ['count' => $size]);
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    private function worker(array $queue): DoctorFinding
    {
        $pending = $queue['pending'] ?? null;
        $reserved = $queue['reserved'] ?? null;

        if (! is_int($pending) || ! is_int($reserved)) {
            return DoctorFinding::warning('queue.worker', 'unknown', details: $queue);
        }

        if ($pending === 0) {
            // Cola vacia: o no hay trabajo o se consume al ritmo que llega. En
            // los dos casos, nada que decir.
            return DoctorFinding::ok('queue.worker', $queue);
        }

        if ($reserved > 0) {
            return DoctorFinding::ok('queue.worker', $queue);
        }

        return DoctorFinding::warning(
            'queue.worker',
            // La hora va ETIQUETADA COMO UTC en los dos idiomas, y no convertida a
            // la zona del centro: `doctor` corre en el contenedor, que vive en
            // UTC (regla dura 3), y la zona del hotel es un dato del centro que
            // esta sonda no tiene por que ir a buscar a la base de datos —que
            // puede ser justo lo que este caido—. Sin la etiqueta, «a las 07:14»
            // se lee como hora local y manda a mirar los logs de dos horas antes.
            params: ['count' => $pending, 'checked_at' => $this->clock->now()->format('H:i')],
            details: $queue,
        );
    }
}
