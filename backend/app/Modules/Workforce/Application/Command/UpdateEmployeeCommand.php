<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Modificacion parcial de una ficha (RF-GP-01).
 *
 * **Los `*Given` no son ruido.** En un `PATCH`, «no enviado» y «enviado a null»
 * son dos ordenes distintas: la primera deja el correo como estaba, la segunda
 * lo borra. Sin la bandera, un `null` de omision borraria el correo o sacaria a
 * alguien de su departamento sin que nadie lo pidiera.
 *
 * `status` solo admite `active` y `suspended`: la baja tiene su propio caso de
 * uso porque lleva fecha y consecuencias (RN-14).
 */
final readonly class UpdateEmployeeCommand
{
    public function __construct(
        public string $uuid,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $email = null,
        public bool $emailGiven = false,
        public ?int $siteId = null,
        public ?int $departmentId = null,
        public bool $departmentGiven = false,
        public ?string $status = null,
        public ?string $locale = null,
    ) {}
}
