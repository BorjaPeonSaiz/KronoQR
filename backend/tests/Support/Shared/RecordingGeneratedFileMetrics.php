<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\GeneratedFileMetrics;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileClass;

/**
 * Las cuatro series de los ficheros generados, apuntadas en memoria (ADR-045).
 *
 * La forma exacta en Redis la prueba `GeneratedFileMetricsTest` contra Redis de
 * verdad; aqui basta con saber que se movio, para que clase y cuantas veces.
 */
final class RecordingGeneratedFileMetrics implements GeneratedFileMetrics
{
    /** @var list<string> */
    public array $orphans = [];

    /** @var list<string> */
    public array $refused = [];

    /** @var list<string> */
    public array $missing = [];

    /** @var list<string> */
    public array $removeFailed = [];

    /** @var array<string, int> */
    public array $overdue = [];

    public function orphanRemoved(GeneratedFileClass $class): void
    {
        $this->orphans[] = $class->value;
    }

    public function refused(GeneratedFileClass $class): void
    {
        $this->refused[] = $class->value;
    }

    public function missing(GeneratedFileClass $class): void
    {
        $this->missing[] = $class->value;
    }

    public function removeFailed(GeneratedFileClass $class): void
    {
        $this->removeFailed[] = $class->value;
    }

    public function overdue(GeneratedFileClass $class, int $count): void
    {
        $this->overdue[$class->value] = $count;
    }

    /** Instala el doble en el contenedor y lo devuelve. */
    public static function install(): self
    {
        $metrics = new self;

        app()->instance(GeneratedFileMetrics::class, $metrics);

        return $metrics;
    }
}
