<?php

declare(strict_types=1);

namespace Tests\Support\Observability;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Un trabajo que existe para ser medido: `queue_job_duration_seconds{job}` y
 * `queue_jobs_failed_total{job}` (doc 02 §8.2, decision 4 de la ficha 3.1).
 *
 * **Por que un trabajo de laboratorio y no uno del producto.** Lo que se
 * comprueba es la costura entre los eventos del *worker* y Redis, y para eso
 * hace falta poder decidir si el trabajo termina o revienta. Ninguno de los
 * trabajos del producto falla a peticion —`GenerateDataExportJob` atrapa lo que
 * le echen y lo escribe en el log a proposito—, asi que forzar el fallo de uno
 * de ellos exigiria averiar el caso de uso que hay detras y la prueba pasaria a
 * hablar de otra cosa. La serie no distingue: la etiqueta es la clase corta,
 * venga de donde venga, y una prueba hermana la comprueba sobre un trabajo real.
 *
 * `$tries = 1` para que el primer tropiezo sea el definitivo: `JobFailed` es «se
 * acabaron los intentos», no «fallo una vez».
 */
final class ProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(private readonly bool $shouldFail = false) {}

    /** El trabajo que revienta, para el contador de fallos. */
    public static function failing(): self
    {
        return new self(shouldFail: true);
    }

    public function handle(): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('Fallo deliberado para medir queue_jobs_failed_total.');
        }
    }
}
