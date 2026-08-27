<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Un periodo de trabajo tal y como se entrega a la Inspeccion (RF-IN-05,
 * RL-01, RL-06).
 *
 * ## Un turno de noche es UN tramo
 *
 * `localClockedInAt` y `localClockedOutAt` llevan la fecha ademas de la hora
 * precisamente por esto: un turno 22:00 → 06:00 se escribe como una sola fila,
 * con entrada del dia 14 y salida del 15, atribuida a la jornada del 14 (RN-05,
 * regla dura 4, ADR-006). Escribir solo `22:00 → 06:00` haria pensar en un turno
 * de menos veinte horas.
 *
 * ## Las horas van dos veces, y no es redundancia
 *
 * La local es la que el trabajador vivio y la que consta en su contrato; la UTC
 * es la que esta almacenada (regla dura 3) y la unica que no cambia de
 * significado en el fin de semana en que se adelanta el reloj. Un documento con
 * solo la local no se puede auditar en un cambio de hora; uno con solo la UTC
 * dice que alguien entro a las 05:00 cuando el reloj de la puerta marcaba las
 * 07:00.
 *
 * ## `dayTotal` se repite en cada tramo del dia
 *
 * Es el total de la jornada, no el de la fila. Va repetido porque el fichero
 * tiene que poder ordenarse y filtrarse sin perder el total, y porque es la
 * cifra que se compara con la nomina. Suma **solo los tramos vigentes**: un
 * tramo anulado figura en el documento —nada se oculta— pero no cuenta horas.
 */
final readonly class ExportedShiftEntry implements LegalExportRecord
{
    public function __construct(
        private ExportedSubject $subject,
        /** Orden del tramo dentro de la jornada, empezando en 1. */
        public int $entryNumber,
        /** `shift_entries.uuid`: identifica una VERSION del tramo (ADR-035). */
        public string $shiftEntryUuid,
        /** `YYYY-MM-DD HH:MM` en la zona del centro. */
        public string $localClockedInAt,
        /** Vacio mientras el tramo siga abierto. */
        public string $localClockedOutAt,
        /** `YYYY-MM-DDTHH:MM:SSZ`, que es como esta almacenado. */
        public string $utcClockedInAt,
        public string $utcClockedOutAt,
        public ExportedDuration $duration,
        public ExportedDuration $dayTotal,
        /** `open`, `closed`, `anomalous` o `voided`. Nunca `superseded`. */
        public string $status,
        /** `qr_kiosk`, `pin_kiosk`, `manual_admin` o `import` (RL-01). */
        public string $clockInSource,
        public string $clockOutSource,
    ) {}

    public function type(): LegalExportRecordType
    {
        return LegalExportRecordType::ShiftEntry;
    }

    public function subject(): ExportedSubject
    {
        return $this->subject;
    }
}
