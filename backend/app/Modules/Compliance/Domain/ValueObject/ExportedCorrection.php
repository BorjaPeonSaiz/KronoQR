<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Una rectificacion del registro horario, con **autor, momento y motivo**
 * (RN-13, RL-04, regla dura 5).
 *
 * ## Por que esto no puede faltar en la exportacion
 *
 * Un registro horario que solo enseñara el resultado final seria indistinguible
 * de uno reescrito. Lo que hace defendible el de este producto es que cada
 * cambio consta: quien lo hizo, cuando, desde que valor, hasta cual y por que.
 * Un informe que lo omitiera convertiria una fortaleza del sistema en una
 * sospecha, y por eso el plan de la tarea 1.17 lo dice sin matices: «un informe
 * que las oculte no cumple».
 *
 * ## El autor es una persona con nombre
 *
 * `authorName` es el nombre de la cuenta de gestion que firmo, no un
 * identificador. Es lo unico que responde a la pregunta que hace una inspeccion
 * —«¿quien cambio estas horas?»— y va acompañado del `authorUuid` para poder
 * casarlo con `audit_log` sin ambiguedad. Como el resto del fichero, es un dato
 * personal que aqui esta por su finalidad legal, y que nunca sale por un log
 * tecnico (regla dura 21).
 *
 * ## `reasonText` solo existe cuando el catalogo no basta
 *
 * Los nueve motivos del Anexo C son cerrados; `OTROS` obliga a una explicacion
 * de veinte caracteres, que el esquema comprueba con un `CHECK`. Ese texto lo
 * escribio una persona sobre otra persona: entra en este documento —forma parte
 * de la explicacion que se debe a la Inspeccion— y no entra en ningun log.
 */
final readonly class ExportedCorrection implements LegalExportRecord
{
    public function __construct(
        private ExportedSubject $subject,
        /** La version del tramo que esta correccion produjo, o que anulo (ADR-035). */
        public string $shiftEntryUuid,
        /** `YYYY-MM-DD HH:MM` en la zona del centro. */
        public string $localPerformedAt,
        public string $utcPerformedAt,
        public string $authorName,
        public string $authorUuid,
        /** `created`, `modified`, `closed` o `voided`: los cuatro verbos de RF-PA-04. */
        public string $action,
        /** Uno de los nueve codigos del Anexo C del doc 01. */
        public string $reasonCode,
        /** Explicacion libre. Obligatoria con `OTROS`, ausente casi siempre. */
        public string $reasonText,
        public ExportedMarks $before,
        public ExportedMarks $after,
    ) {}

    public function type(): LegalExportRecordType
    {
        return LegalExportRecordType::Correction;
    }

    public function subject(): ExportedSubject
    {
        return $this->subject;
    }
}
