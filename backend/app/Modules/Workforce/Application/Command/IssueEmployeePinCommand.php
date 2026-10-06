<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

use App\Modules\Workforce\Application\Port\PinMaterial;
use App\Modules\Workforce\Application\UseCase\IssueEmployeePinHandler;

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
        /**
         * `true` cuando se pide sustituir el PIN (el restablecimiento). El
         * asiento dice `pin.reset` solo si de verdad habia PIN: sobre una ficha
         * pendiente es la primera emision y queda `pin.issued`. Lo decide la
         * emision con la cadena tomada, no quien llama.
         */
        public bool $reset,
        /**
         * PIN y hash **ya calculados**, siempre (ADR-046 §1.1 punto 5, A-3).
         *
         * Con el coste 12 de produccion, cada hash cuesta unos 160 ms. Calculado
         * dentro de la emision, correria con el candado de la cadena de
         * `audit_log` tomado, y cada alta o restablecimiento congelaria ~160 ms
         * todos los fichajes del hotel. Por eso quien llama lo calcula **antes**
         * de abrir nada —{@see IssueEmployeePinHandler::freshMaterial()}— y
         * aqui dentro solo quedan las escrituras. (La importacion masiva ya no
         * calcula ninguno: deja el PIN pendiente, RF-GP-05.)
         *
         * **No cambia ninguna garantia**: en el alta individual el PIN se sigue
         * escribiendo en la misma transaccion que la ficha, asi que de ella no
         * sale nadie sin PIN.
         */
        public PinMaterial $material,
    ) {}
}
