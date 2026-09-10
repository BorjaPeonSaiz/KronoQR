<?php

declare(strict_types=1);

namespace App\Support\Observability\Metrics;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

/**
 * `queue_job_duration_seconds{job}` y `queue_jobs_failed_total{job}`
 * (doc 02 §8.2, decision 4 de la ficha 3.1).
 *
 * ## La etiqueta es la CLASE CORTA del trabajo, nunca su carga
 *
 * Mismo criterio que `RecordHttpMetrics` con el nombre de la ruta: la
 * cardinalidad de esta metrica es el numero de trabajos del producto —una
 * decena— y no crece con el trafico. Con el identificador del trabajo, o con
 * cualquier cosa de su carga, habria una serie por fichaje reenviado, y ademas
 * un directorio de identificadores en un sistema sin control de acceso ni
 * retencion (regla dura 21).
 *
 * ## `JobFailed` es «se acabaron los intentos», no «fallo una vez»
 *
 * Laravel emite `JobFailed` cuando el trabajo agota sus reintentos y va a la
 * tabla de fallidos. Es el hecho que interesa: un fallo transitorio que el
 * segundo intento resuelve no es una averia, y contarlo haria que la serie
 * subiera cada vez que Redis parpadea.
 *
 * ## Medir no puede tumbar un trabajo
 *
 * Todo envuelto, igual que en el borde HTTP. Un trabajo de la cola puede ser el
 * reenvio de un fichaje de la cola offline del quiosco (RF-AT-10): que se
 * pierda por no poder incrementar un contador seria la regla dura 19 rota desde
 * dentro.
 */
final class QueueJobMetrics
{
    public const string DURATION = 'queue_job_duration_seconds';

    public const string FAILED_TOTAL = 'queue_jobs_failed_total';

    /**
     * Cubos en segundos. Un trabajo en cola no vive con el presupuesto de una
     * peticion —nadie lo espera delante de una tablet—, asi que los cubos
     * cubren de «instantaneo» a «esto lleva un minuto y hay que mirarlo».
     *
     * @var list<float>
     */
    private const array BUCKETS = [0.05, 0.25, 1.0, 5.0, 15.0, 60.0, 300.0];

    /**
     * Marca de inicio por identificador de trabajo.
     *
     * **Un mapa y no una propiedad suelta**: un *worker* procesa trabajos uno
     * detras de otro en el mismo proceso, y un trabajo que muere sin su
     * `JobProcessed` dejaria la marca del siguiente contaminada. Se borra al
     * cerrar, asi que el mapa nunca tiene mas de un elemento en marcha.
     *
     * @var array<string, float>
     */
    private array $startedAt = [];

    public function __construct(private readonly RedisMetricWriter $writer) {}

    public function starting(JobProcessing $event): void
    {
        try {
            $this->startedAt[$this->identifierOf($event->job->getJobId(), $event->job->uuid())] = microtime(true);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }

    public function processed(JobProcessed $event): void
    {
        try {
            $this->observe($this->identifierOf($event->job->getJobId(), $event->job->uuid()), $event->job->resolveName());
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }

    public function failed(JobFailed $event): void
    {
        try {
            $job = $this->shortNameOf($event->job->resolveName());

            $this->observe($this->identifierOf($event->job->getJobId(), $event->job->uuid()), $event->job->resolveName());
            $this->writer->increment(self::FAILED_TOTAL, 'job='.$job);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }

    private function observe(string $identifier, string $name): void
    {
        $startedAt = $this->startedAt[$identifier] ?? null;

        unset($this->startedAt[$identifier]);

        if ($startedAt === null) {
            return;
        }

        $seconds = microtime(true) - $startedAt;

        $this->writer->observeMany(
            self::DURATION,
            'job='.$this->shortNameOf($name),
            self::BUCKETS,
            RedisMetricWriter::tallyBuckets(self::BUCKETS, $seconds),
            $seconds,
            1,
        );
    }

    private function identifierOf(string|int|null $jobId, ?string $uuid): string
    {
        return $jobId === null ? ($uuid ?? '') : (string) $jobId;
    }

    /**
     * `App\Modules\Kiosk\…\SendPairingNotice` -> `SendPairingNotice`.
     *
     * El nombre completo con su ruta de espacios de nombres no aporta nada en
     * una etiqueta y hace ilegible cualquier leyenda de Grafana.
     */
    private function shortNameOf(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
