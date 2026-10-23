<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Port\ScanFailureLedger;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;

/**
 * **La purga tecnica de `scan_processing_failures`** (RN-22, ADR-061 §1, doc
 * 01 §5.5): los intentos fallidos de mas de 30 dias se borran.
 *
 * **No es la purga del registro legal** (RL-02): esta tabla no es evidencia, no
 * tiene datos personales y no pasa por la funcion de purga ni deja asiento. Lo
 * que si es constancia —el descarte— vive en `scan_events` y en
 * `discarded_scan_reports`, con la retencion del registro.
 *
 * **Treinta dias y no un ajuste**: la historia solo sirve para decidir el
 * descarte —que exige como mucho la ventana configurada, minutos u horas— y para
 * diagnosticar un defecto reciente. Mas tiempo no aporta nada y si acumula filas
 * en el servidor del cliente, al que no podemos entrar (ADR-016).
 */
final readonly class PruneScanProcessingFailures
{
    public const int RETENTION_DAYS = 30;

    public function __construct(
        private ScanFailureLedger $ledger,
        private Clock $clock,
    ) {}

    /**
     * @return int Intentos borrados.
     */
    public function handle(): int
    {
        return UseCaseInstrumentation::run(
            'attendance.prune_scan_processing_failures',
            fn (): int => $this->ledger->purgeFailedBefore(
                $this->clock->now()->modify('-'.self::RETENTION_DAYS.' days'),
            ),
            static fn (int $deleted): array => ['purge.deleted' => $deleted],
        );
    }
}
