<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Infrastructure\Diagnostics\QueueSupervisors;
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
 * ## `queue.worker` pregunta al supervisor, y si no puede, no afirma
 *
 * Primero se pregunta a Horizon si su supervisor ha dado señales de vida en los
 * ultimos segundos ({@see QueueSupervisors}): es lo unico que distingue «nadie
 * consume» de «no hay nada que consumir» con la cola vacia. Hasta la 2.2.0 esta
 * sonda solo miraba la cola y, vacia, decia «hay alguien consumiendo» con
 * Horizon parado (V3-PL-07, V4-PL-3): el «ok optimista» que este mismo
 * comentario decia evitar.
 *
 * Si no se puede preguntar al supervisor, se mira la cola como antes, pero sin
 * inventar: con trabajos encolados y ninguno reservado sale `warning` con el
 * comando para comprobarlo; con trabajos en proceso, alguien los coge; con la
 * cola vacia, `ok` con un texto que dice que **no se sabe** si hay consumidor.
 *
 * Horizon PARADO es `failure`, la misma gravedad que le da `doctor.sh` desde
 * fuera: fichar no depende de la cola, pero sin el no termina ninguna
 * exportacion integra (la de la Inspeccion o la de quien ejerce su derecho de
 * acceso), ningun informe en diferido ni ningun aviso de incidencias, y nada lo
 * dice. `update.sh` ejecuta `doctor` DESPUES de arrancar los trabajadores y de
 * esperar a que Horizon de señales de vida, asi que una actualizacion correcta no
 * lo ve parado. En PAUSA es `warning`: alguien lo ha pausado, normalmente a
 * proposito.
 */
final readonly class QueueProbe implements DoctorProbe
{
    public function __construct(
        private ServiceInspector $services,
        private Clock $clock,
        private QueueSupervisors $supervisors,
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
        $supervisors = $this->supervisors->status();
        $details = [...$queue, 'supervisors' => $supervisors];
        $pending = $queue['pending'] ?? null;
        $params = [
            'count' => is_int($pending) ? $pending : 0,
            'masters' => $supervisors['masters'] ?? 0,
            'checked_at' => $this->checkedAt(),
        ];

        return match ($supervisors['status']) {
            QueueSupervisors::RUNNING => DoctorFinding::ok('queue.worker', $details, $params),
            QueueSupervisors::PAUSED => DoctorFinding::warning('queue.worker', 'paused', $params, $details),
            QueueSupervisors::INACTIVE => DoctorFinding::failure('queue.worker', 'stopped', $params, $details),
            default => $this->workerFromQueue($queue, $details, $params),
        };
    }

    /**
     * Sin poder preguntar al supervisor: lo que dice la cola, y solo eso.
     *
     * @param  array<string, mixed>  $queue
     * @param  array<string, mixed>  $details
     * @param  array<string, int|string>  $params
     */
    private function workerFromQueue(array $queue, array $details, array $params): DoctorFinding
    {
        $pending = $queue['pending'] ?? null;
        $reserved = $queue['reserved'] ?? null;

        if (! is_int($pending) || ! is_int($reserved)) {
            return DoctorFinding::warning('queue.worker', 'unknown', details: $details);
        }

        if ($reserved > 0) {
            // Hay trabajos reservados: alguien los ha cogido. Eso si se sabe.
            return new DoctorFinding('queue.worker', DoctorStatus::Ok, $params, $details, 'busy');
        }

        if ($pending === 0) {
            // Cola vacia y nada en proceso: o no hay trabajo o no hay nadie, y
            // desde aqui no se distingue. `ok` porque no hay nada atascado, con
            // un texto que no afirma que haya consumidor.
            return new DoctorFinding('queue.worker', DoctorStatus::Ok, $params, $details, 'idle');
        }

        return DoctorFinding::warning('queue.worker', params: $params, details: $details);
    }

    /**
     * La hora va ETIQUETADA COMO UTC en los dos idiomas, y no convertida a la
     * zona del centro: `doctor` corre en el contenedor, que vive en UTC (regla
     * dura 3), y la zona del hotel es un dato del centro que esta sonda no tiene
     * por que ir a buscar a la base de datos —que puede ser justo lo que este
     * caido—. Sin la etiqueta, «a las 07:14» se lee como hora local y manda a
     * mirar los logs de dos horas antes.
     */
    private function checkedAt(): string
    {
        return $this->clock->now()->format('H:i');
    }
}
