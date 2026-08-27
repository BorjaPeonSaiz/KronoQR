<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Alta de empleado, ya validada por el `FormRequest` (RF-GP-01).
 *
 * **No lleva `employeeCode`**: lo genera el servidor. Aceptarlo del cliente
 * dejaria la opacidad del codigo en manos de quien rellena el formulario, y el
 * primero que teclee «RECEPCION-01» habra impreso un dato personal en una
 * tarjeta (doc 01 §5.5).
 *
 * `nationalId` viaja en claro **solo hasta el repositorio**, que lo convierte en
 * digest en la misma sentencia (RL-08). Ni se registra, ni se devuelve, ni se
 * guarda.
 */
final readonly class RegisterEmployeeCommand
{
    public function __construct(
        public int $siteId,
        public ?int $departmentId,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $nationalId,
        public string $hiredAt,
        public string $locale,
    ) {}
}
