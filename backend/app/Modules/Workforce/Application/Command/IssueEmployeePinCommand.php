<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Emitir un PIN para una persona (RF-ID-09), sea en su alta o al restablecerlo.
 *
 * `siteId` llega resuelto por quien llama: en el alta ya lo tiene y en el
 * restablecimiento sale de la ficha que hubo que cargar para saber si existe. Es
 * la etiqueta de `pin_resets_total{site}` y no identifica a nadie.
 *
 * **No lleva actor.** Quien hizo la peticion lo resuelve el asiento de
 * auditoria a partir de la sesion en curso, que es donde ese dato vive
 * (`Compliance\Infrastructure\Audit\CurrentAuditContext`). Pasarlo por aqui
 * obligaria a que cada capa lo arrastrara y a que un comando de consola se
 * inventara uno.
 */
final readonly class IssueEmployeePinCommand
{
    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        /** `true` cuando sustituye a un PIN anterior: distingue `pin.reset` de `pin.issued`. */
        public bool $reset,
    ) {}
}
