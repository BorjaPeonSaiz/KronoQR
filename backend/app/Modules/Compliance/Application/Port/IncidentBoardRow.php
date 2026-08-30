<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\Model\Incident;

/**
 * Una fila de la bandeja: la incidencia mas lo que hace falta para trabajarla
 * (RF-PA-05).
 *
 * **Envuelve el agregado en vez de copiarlo.** El tipo, la severidad, el estado,
 * la jornada, el contexto y los tres campos del cierre se leen de
 * {@see Incident}, que es donde estan sus invariantes; esta clase solo añade lo
 * que el agregado no tiene por no ser suyo: el identificador de la fila, quien es
 * la persona afectada y como se llaman las cuentas implicadas. Copiar los campos
 * habria creado una segunda verdad sobre el mismo hecho, y dos formas de decir lo
 * mismo acaban discrepando.
 *
 * **El `id` esta aqui y no en el agregado**, igual que en el resto del modulo:
 * es la clave de una tabla y no una propiedad de la incidencia. Es lo que va en
 * la URL de `POST /api/v1/incidents/{id}/resolve` y lo que apunta
 * `audit_log.subject_id`.
 */
final readonly class IncidentBoardRow
{
    public function __construct(
        public int $id,
        public Incident $incident,
        public IncidentSubject $subject,
        /** El responsable del departamento. `null` si el departamento no tiene ninguno. */
        public ?IncidentActor $assignedTo,
        /** Quien la cerro. `null` mientras siga abierta. */
        public ?IncidentActor $resolvedBy,
    ) {}
}
