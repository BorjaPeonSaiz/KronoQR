<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\Policy\UnprocessableScanPolicy;
use DateTimeImmutable;

/**
 * **La cuenta de los fallos de cada fichaje** (RN-22, ADR-061 §1), sobre
 * `scan_processing_failures`.
 *
 * Solo se usa en el camino del fallo: el fichaje que sale bien no gana ninguna
 * escritura ni lectura (RNF-P-01).
 */
interface ScanFailureLedger
{
    /**
     * Apunta el intento **en su propia transaccion**, despues del `ROLLBACK`
     * del caso de uso, y devuelve la historia del fichaje con el intento ya
     * incluido.
     *
     * La historia cuenta solo los intentos con el **mismo quiosco y la misma
     * huella** que el primer intento apuntado de ese `scan_id`: nadie con otro
     * token puede envenenar el `scan_id` de otro quiosco. Si el intento actual
     * no coincide con el primero, se apunta igual —es historia del fallo— y
     * devuelve `null`: no suma a nada.
     *
     * Si no se puede escribir, lanza. Quien llama no cuenta ese intento, que es
     * lo correcto: si lo que falla es PostgreSQL, es una dependencia caida y no
     * un veneno.
     */
    public function record(ScanFailureAttempt $attempt): ?RecordedScanFailures;

    /**
     * Descartes **del servidor** de este quiosco con `recorded_at >= $since`. El
     * instante lo da {@see UnprocessableScanPolicy::budgetWindowStartsAt()}:
     * dos definiciones de «las ultimas 24 h» acabarian discrepando en el borde.
     */
    public function serverDiscardsSince(int $deviceId, DateTimeImmutable $since): int;

    /**
     * La purga tecnica: borra los intentos con `failed_at` anterior a
     * `$cutoff`. Devuelve cuantos borro. No es la purga del registro legal:
     * esta tabla no es evidencia.
     */
    public function purgeFailedBefore(DateTimeImmutable $cutoff): int;
}
