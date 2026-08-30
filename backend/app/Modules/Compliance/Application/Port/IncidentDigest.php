<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * El resumen que recibe **un** responsable por **una** ejecucion de la deteccion
 * (RF-PR-01).
 *
 * **Uno por persona y por pasada, no uno por incidencia.** Una madrugada con
 * quince hallazgos en el mismo departamento son quince correos que nadie lee, y
 * un aviso que no se lee es lo mismo que no avisar. Agrupar es lo que hace que
 * el correo siga significando algo dentro de seis meses.
 */
final readonly class IncidentDigest
{
    /**
     * @param  list<IncidentNotice>  $incidents  Nunca vacia: sin incidencias no se envia nada.
     */
    public function __construct(
        public int $managerUserId,
        public string $email,
        /** Idioma de la cuenta (`users.locale`): el aviso se escribe en el suyo. */
        public string $locale,
        public array $incidents,
    ) {}

    /**
     * @return list<int>
     */
    public function incidentIds(): array
    {
        return array_map(static fn (IncidentNotice $notice): int => $notice->incidentId, $this->incidents);
    }
}
