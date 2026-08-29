<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;

/**
 * Como acabo un intento de acceso al panel: **con sesion** o **con un reto de
 * segundo factor** (RF-ID-01, RS-06).
 *
 * ## Por que no es un array con una clave mas
 *
 * Antes de la tarea 2.1, el caso de uso devolvia `array{user, token}` y el
 * controlador serializaba siempre lo mismo. Con el segundo factor hay dos
 * desenlaces que **no se pueden confundir**: en uno hay una sesion utilizable y
 * en el otro hay un token que no autoriza nada del producto. Un array con un
 * `pending` opcional deja que alguien lo ignore y sirva el token pendiente como
 * si fuera una sesion; con dos constructores nombrados y un `isPending()`
 * explicito, el controlador tiene que decidir.
 *
 * Es la misma razon por la que el contrato usa `200` y `202` con nombres de campo
 * distintos en lugar de un `oneOf` sobre `200`.
 *
 * ## Los dos tokens son de la misma clase y no son la misma cosa
 *
 * En los dos casos el valor es un {@see IssuedAccessToken} —un token de Sanctum
 * con su caducidad— y lo que cambia son sus ambitos: el de la sesion lleva los
 * del rol; el del reto lleva solo `2fa:pending`, que no abre ninguna pantalla del
 * panel. La diferencia la garantiza el emisor, no este objeto.
 */
final readonly class LoginOutcome
{
    private function __construct(
        public AuthenticatedUser $user,
        public IssuedAccessToken $token,
        private bool $pending,
        public bool $enrolmentRequired,
    ) {}

    /**
     * Acceso completo: la cuenta no necesitaba segundo factor, o ya lo verifico.
     */
    public static function session(AuthenticatedUser $user, IssuedAccessToken $token): self
    {
        return new self($user, $token, false, false);
    }

    /**
     * Falta el segundo factor. El token solo sirve para `/auth/2fa/*`.
     *
     * @param  bool  $enrolmentRequired  `true` si ademas hay que dar de alta el TOTP
     *                                   antes de poder verificarlo (RS-06).
     */
    public static function challenge(
        AuthenticatedUser $user,
        IssuedAccessToken $token,
        bool $enrolmentRequired,
    ): self {
        return new self($user, $token, true, $enrolmentRequired);
    }

    public function isPending(): bool
    {
        return $this->pending;
    }
}
