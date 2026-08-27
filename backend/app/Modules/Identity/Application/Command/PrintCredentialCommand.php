<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

use InvalidArgumentException;

/**
 * Imprimir **una** tarjeta (RF-QR-04, ADR-034).
 *
 * **Dos formas de nombrar la misma credencial, y las dos hacen falta.** El
 * endpoint la identifica por el UUID de la credencial —es el recurso de la
 * URL— y el comando de consola por el UUID del empleado, porque quien esta
 * delante de una terminal a las siete de la mañana sabe a quien le falta la
 * tarjeta, no que credencial le corresponde. Resolver la equivalencia aqui, y no
 * en el comando de consola, deja la busqueda en la capa que puede hacerla y
 * evita que la consola acabe con logica que el endpoint no tiene.
 *
 * **No hay ninguna bandera de reimpresion, y no puede haberla** (ADR-034). Una
 * credencial ya impresa responde `409`. Reponer una tarjeta es revocar, reemitir
 * e imprimir la nueva: tres actos, tres asientos de auditoria.
 */
final readonly class PrintCredentialCommand
{
    private function __construct(
        public ?string $credentialUuid,
        public ?string $employeeUuid,
        public ?int $actorUserId,
    ) {
        if ($credentialUuid === null && $employeeUuid === null) {
            throw new InvalidArgumentException('Imprimir necesita la credencial o el empleado al que pertenece.');
        }
    }

    public static function forCredential(string $credentialUuid, ?int $actorUserId = null): self
    {
        return new self($credentialUuid, null, $actorUserId);
    }

    /**
     * La credencial **activa** de ese empleado. Si tiene una revocada y ninguna
     * viva, no hay nada que imprimir: hay que emitir primero.
     */
    public static function forEmployee(string $employeeUuid, ?int $actorUserId = null): self
    {
        return new self(null, $employeeUuid, $actorUserId);
    }
}
