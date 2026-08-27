<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Domain\Model\Credential;

/**
 * Lo que devuelve una emision: la credencial persistida, **pendiente de
 * imprimir**.
 *
 * **Aqui no hay ningun secreto** (ADR-034). El token no se acuña al emitir sino
 * al imprimir, dentro del PDF de la tarjeta, asi que este objeto no lleva nada
 * que permita fichar por nadie. Es la diferencia con la version anterior de esta
 * clase, que transportaba el payload en claro por la respuesta de la API y por
 * la salida de un comando de consola.
 *
 * `employeeUuid` viaja para que quien construya la respuesta no tenga que
 * volver a traducir la clave interna, y porque es lo que necesita el asiento de
 * auditoria.
 */
final readonly class IssuedCredential
{
    public function __construct(
        public int $credentialId,
        public Credential $credential,
        public string $employeeUuid,
        /** Si esta emision revoco una credencial anterior. */
        public bool $reissue,
    ) {}
}
