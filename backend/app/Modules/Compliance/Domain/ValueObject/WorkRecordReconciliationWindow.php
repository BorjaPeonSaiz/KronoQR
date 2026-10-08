<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\InvalidWorkRecordReconciliationWindow;
use App\Modules\Compliance\Domain\WorkRecordReconciliation;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Que tramos y que asientos entran en una pasada de la conciliacion (ADR-057 §4).
 *
 * ## La ventana diaria, y por que basta con mirar los asientos recientes
 *
 * Entran los tramos con `work_date` desde hace `days` dias y **todos** los
 * asientos `shift_entry.*` escritos desde dos dias antes. Las dos mitades
 * encajan por construccion:
 *
 * - **Todo asiento de un tramo de la ventana cae dentro del margen.** El primero
 *   se fecha en su entrada (`occurred_at` del fichaje, regla dura 9), y la
 *   entrada de una jornada `D` ocurre, como muy pronto, catorce horas antes de
 *   la medianoche UTC de `D` (UTC+14). Los dos dias de margen cubren eso con
 *   holgura; cerrar, corregir y anular solo pueden venir despues.
 * - **El ultimo asiento de cualquier tramo tocado en la ventana esta en la
 *   ventana.** Una correccion de hoy sobre un tramo de hace un mes produce un
 *   asiento de hoy, y ese es el que manda: es posterior a todos los demas.
 *
 * Lo que la ventana diaria **no** ve es lo que pasa en un tramo antiguo sin que
 * la aplicacion escriba nada: un `UPDATE` o un `DELETE` directo sobre un fichaje
 * de hace meses. Eso es exactamente lo que recoge la pasada completa semanal.
 *
 * ## La completa no tiene limites
 *
 * Todos los tramos y todos los asientos vivos. Un asiento cuyo tramo ya no esta
 * se distingue de una purga legitima por la fecha de corte de la ultima purga
 * auditada, no por la ventana (ver {@see WorkRecordReconciliation}).
 */
final readonly class WorkRecordReconciliationWindow
{
    /**
     * Dias de margen por delante de la ventana para los asientos. Ver la
     * cabecera: cubre el desfase entre la fecha civil de una jornada y el
     * instante UTC de su primera entrada.
     */
    public const int AUDIT_MARGIN_DAYS = 2;

    private function __construct(
        public WorkRecordReconciliationScope $scope,
        /** Primer instante de los asientos que entran, o `null` en la pasada completa. */
        public ?DateTimeImmutable $auditSince,
        /** Primera `work_date` (`YYYY-MM-DD`) de los tramos que entran, o `null` en la pasada completa. */
        public ?string $fromWorkDate,
        /** Dias de la ventana, o `null` en la pasada completa. */
        public ?int $days,
    ) {}

    /**
     * La ventana de los ultimos `$days` dias contados desde la fecha UTC de
     * `$now`.
     *
     * En UTC y no en la zona del centro a proposito: la ventana es un rango de
     * busqueda, no una jornada, y los dos dias de margen absorben de sobra la
     * diferencia (regla dura 3).
     */
    public static function recent(DateTimeImmutable $now, int $days): self
    {
        if ($days < 1) {
            throw InvalidWorkRecordReconciliationWindow::notPositive($days);
        }

        $today = $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $from = $today->sub(new DateInterval('P'.$days.'D'));

        return new self(
            WorkRecordReconciliationScope::Recent,
            $from->sub(new DateInterval('P'.self::AUDIT_MARGIN_DAYS.'D')),
            $from->format('Y-m-d'),
            $days,
        );
    }

    public static function full(): self
    {
        return new self(WorkRecordReconciliationScope::Full, null, null, null);
    }
}
