<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use InvalidArgumentException;

/**
 * El secreto TOTP recien generado y su URI `otpauth://` (RS-06).
 *
 * **Es la unica vez que el secreto existe fuera de la base de datos.** Se
 * serializa en la respuesta de `POST /api/v1/auth/2fa/enrol` y se olvida: no se
 * registra en ningun log, no viaja a `audit_log` y no vuelve en ninguna consulta
 * posterior. Es el mismo criterio de {@see IssuedAccessToken} y del PIN de
 * RF-ID-09 — se enseña una vez y despues solo puede volver a generarse otro.
 *
 * **Por que la URI se transporta y no se compone en el cliente.** Lleva el
 * algoritmo, los digitos y el periodo, que son las mismas decisiones con las que
 * el servidor va a verificar el codigo. Si las pusiera el navegador, un cambio en
 * el servidor no llegaria a los tres frontends a la vez y el sintoma seria un
 * autenticador que genera codigos que nadie acepta.
 */
final readonly class TwoFactorEnrolment
{
    public function __construct(
        public string $secret,
        public string $otpauthUri,
    ) {
        if ($secret === '') {
            throw new InvalidArgumentException('Un alta de segundo factor necesita su secreto.');
        }

        if (! str_starts_with($otpauthUri, 'otpauth://')) {
            // Sin esto, un adaptador que devolviera una cadena vacia o una URL
            // cualquiera produciria un QR que el autenticador acepta sin quejarse
            // y que despues no genera codigos validos.
            throw new InvalidArgumentException('La URI del autenticador tiene que ser una otpauth://.');
        }
    }
}
