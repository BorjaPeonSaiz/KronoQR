<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\CaughtFailureReporter;
use Throwable;

/**
 * Apunta los fallos que un caso de uso captura y manda al historico de errores,
 * en orden. Para las pruebas sin base de datos.
 */
final class RecordingCaughtFailures implements CaughtFailureReporter
{
    /** @var list<Throwable> */
    public array $reported = [];

    public function report(Throwable $failure): void
    {
        $this->reported[] = $failure;
    }
}
