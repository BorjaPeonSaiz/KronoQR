<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Adapter;

use App\Modules\Attendance\Application\Port\ScanFailureClassifier;
use App\Modules\Shared\Domain\Exception\BusinessRejection;
use App\Support\Resilience\DependencyFailure;
use PDOException;
use Throwable;

/**
 * **Que fallos cuentan para descartar un fichaje** (RN-22, ADR-061 §2): todo lo
 * que no se reconoce como transitorio ni como un desenlace.
 *
 * ## La dependencia caida es la de ADR-059, no una copia
 *
 * {@see DependencyFailure::dependencyOf()} es el mismo predicado que decide el
 * `503 service-unavailable` de toda la instalacion. Si ADR-059 aprende un
 * SQLSTATE o un mensaje de libpq nuevo, este adaptador lo aprende con el. Una
 * copia aqui acabaria discrepando justo en una caida, que es cuando contar mal
 * convierte fichajes validos en descartes.
 *
 * ## Lo transitorio por SQLSTATE, recorriendo `previous`
 *
 * `40001` (serializacion), `40P01` (interbloqueo), `55P03` (candado no
 * disponible) y las clases `08` (conexion), `53` (recursos: disco, memoria,
 * conexiones), `57` (cancelacion, `statement_timeout`, apagado) y `58` (E/S).
 * Se mira el SQLSTATE de **cada eslabon** de la cadena: una `RuntimeException`
 * que envuelve una `QueryException` de interbloqueo sigue siendo un
 * interbloqueo.
 *
 * ## La carrera de idempotencia
 *
 * El `23505` del `UNIQUE` de `scan_events.scan_id` es la carrera de dos envios
 * del mismo fichaje (regla dura 8), y se reconoce por el nombre del indice, como
 * hace `DbPairingRequests` con el suyo: con el `23505` a secas, una violacion de
 * otra clave —un dato envenenado de verdad— dejaria de contar.
 *
 * ## Los desenlaces
 *
 * Una `BusinessRejection` en cualquier eslabon: la carrera de RN-01 tras sus
 * reintentos (`ShiftEntryAlreadyOpen`, `OverlappingShiftEntry`), el reenvio
 * (`ScanAlreadyRecorded`) y cualquier otra regla que dice que no.
 *
 * Solo clasifica: no escribe ni informa. El mensaje de la excepcion solo se lee
 * para buscar el nombre del indice, y no sale de este metodo.
 */
final readonly class ScanFailureTransience implements ScanFailureClassifier
{
    /** Contencion: el mismo fichaje saldra bien cuando pase. */
    private const array TRANSIENT_SQLSTATES = ['40001', '40P01', '55P03'];

    /** Clases SQLSTATE transitorias por naturaleza (las dos primeras cifras). */
    private const array TRANSIENT_CLASSES = ['08', '53', '57', '58'];

    /** El `UNIQUE` de la idempotencia del fichaje (regla dura 8). */
    private const string SCAN_ID_UNIQUE = 'scan_events_scan_id_unique';

    public function counts(Throwable $failure): bool
    {
        if (DependencyFailure::dependencyOf($failure) !== null) {
            return false;
        }

        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof BusinessRejection) {
                return false;
            }

            if ($current instanceof PDOException && $this->isTransient($current)) {
                return false;
            }
        }

        return true;
    }

    private function isTransient(PDOException $failure): bool
    {
        $sqlState = \is_array($failure->errorInfo) && \is_string($failure->errorInfo[0] ?? null)
            ? $failure->errorInfo[0]
            : (string) $failure->getCode();

        if (\in_array($sqlState, self::TRANSIENT_SQLSTATES, true)
            || \in_array(substr($sqlState, 0, 2), self::TRANSIENT_CLASSES, true)) {
            return true;
        }

        return $sqlState === '23505' && str_contains($failure->getMessage(), self::SCAN_ID_UNIQUE);
    }
}
