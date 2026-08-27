<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * La orden de emitir una credencial (RF-QR-01, RF-QR-03).
 *
 * El empleado llega por su **UUID publico**, que es el unico identificador de
 * persona que viaja por la API. La traduccion a la clave interna que necesita la
 * clave ajena de `credentials` la hace el caso de uso con el puerto
 * `EmployeeRegistry`.
 *
 * **`reissue` es explicito y no se deduce.** Si el empleado ya tiene tarjeta
 * activa, emitir otra sin decirlo dejaria dos vivas —lo que la invariante del
 * §5.2 prohibe— o revocaria la anterior sin que nadie lo hubiera pedido. Con la
 * bandera, quien reemite esta afirmando que la anterior deja de valer, y por eso
 * `reason` es obligatorio en ese caso: es lo que quedara escrito en `audit_log`
 * cuando alguien pregunte por que esa persona tuvo tres tarjetas.
 */
final readonly class IssueCredentialCommand
{
    public function __construct(
        public string $employeeUuid,
        /** Revoca la credencial activa anterior, si la hay, en el mismo acto. */
        public bool $reissue = false,
        /** Motivo de la revocacion de la anterior. Obligatorio si `reissue`. */
        public ?string $reason = null,
        /** Quien lo pide, o `null` si es un comando de consola. */
        public ?int $actorUserId = null,
    ) {}
}
