<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\Model\TerminationDateCorrection;
use DateTimeImmutable;

/**
 * Una correccion de la fecha de cese tal como la ve la ficha de `rrhh+`
 * (RN-13, RN-27, RL-04; esquema `TerminationDateCorrectionRecord`).
 *
 * **Es una lectura, no el agregado** {@see TerminationDateCorrection}:
 * lleva lo que el agregado no necesita para decidir nada —el nombre de quien
 * corrigio, que es de una cuenta de gestion de otro modulo— y lo que no le hace
 * falta a la escritura.
 *
 * `authorName` es `users.name`: puede salir en la API, nunca en un log tecnico
 * ni en `audit_log`, donde va el uuid (regla dura 21). `reason` es el texto
 * libre de RRHH, o el texto fijo con el que lo sustituye la minimizacion de la
 * ficha (ADR-060).
 */
final readonly class TerminationCorrectionRecord
{
    public function __construct(
        public int $id,
        public string $previousTerminatedOn,
        public string $terminatedOn,
        public DateTimeImmutable $correctedAt,
        public string $authorUuid,
        public string $authorName,
        public string $reason,
    ) {}
}
