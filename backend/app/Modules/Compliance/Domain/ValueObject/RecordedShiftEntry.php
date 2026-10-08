<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Un tramo **tal y como esta escrito en `shift_entries`**, para compararlo con
 * lo que dice su auditoria (ADR-057 §4).
 *
 * Es un modelo de lectura plano y no el `ShiftEntry` de `Attendance`: este
 * modulo no puede importar aquel (doc 02 §1.6) y no debe, porque lo que se
 * compara aqui no es un agregado con invariantes sino lo que **hay en la fila**,
 * que es justo lo que un atacante puede haber cambiado sin pasar por ninguna
 * invariante.
 *
 * Los instantes van como cadenas ISO-8601 en UTC con microsegundos —el formato
 * de `audit_log.payload`— porque la comparacion es literal y tiene que poder
 * detectar un cambio de un microsegundo.
 */
final readonly class RecordedShiftEntry
{
    /**
     * @param  list<string>  $correctionActions  Acciones de las filas de `shift_corrections` que apuntan a este tramo.
     * @param  list<string>  $replacementCorrectionActions  Las de la version que lo sustituyo, si la hay.
     */
    public function __construct(
        public string $uuid,
        public string $employeeUuid,
        public int $siteId,
        public string $workDate,
        public string $clockedInAt,
        public ?string $clockedOutAt,
        public ?int $durationMinutes,
        public string $status,
        public int $version,
        public ?string $supersededByUuid,
        public array $correctionActions = [],
        public array $replacementCorrectionActions = [],
    ) {}

    /**
     * Anulado o sustituido: fuera del conjunto vigente, del total del dia y de
     * la exportacion como tramo (ADR-026). Para la exportacion es un borrado.
     */
    public function isRetired(): bool
    {
        return \in_array($this->status, ['voided', 'superseded'], true);
    }
}
