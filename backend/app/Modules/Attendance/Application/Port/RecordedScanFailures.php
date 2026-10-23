<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\ScanFailureHistory;

/**
 * Lo que {@see ScanFailureLedger::record()} sabe de un fichaje despues de
 * apuntar su ultimo fallo: la historia que decide la politica y, para el log
 * del descarte, las clases de excepcion vistas (ADR-061 §6.4).
 */
final readonly class RecordedScanFailures
{
    /**
     * @param  list<string>  $exceptionClasses  Nombres de clase distintos, sin mensajes.
     */
    public function __construct(
        public ScanFailureHistory $history,
        public array $exceptionClasses,
    ) {}
}
