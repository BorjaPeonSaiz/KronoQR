<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;

/**
 * Una persona candidata de la busqueda de la exportacion legal (RF-IN-05).
 *
 * **Los campos minimos para distinguir a dos personas con el mismo nombre y
 * nada mas**: ni el codigo de empleado, ni el correo, ni el documento ni su
 * digest. El codigo no va aunque se pueda buscar por el: devolverlo convertiria
 * la busqueda en la forma de sacar el codigo de cualquiera a partir de su
 * nombre, y el codigo es lo que lleva impreso la tarjeta.
 *
 * Las fechas son civiles y sin zona (`Y-m-d`), como en la ficha de plantilla.
 * `hiredAt` y `terminatedAt` son las del periodo en curso; `employmentPeriods`,
 * todos, del mas antiguo al mas reciente (RN-27): una persona readmitida no
 * aparece como si solo hubiera trabajado el ultimo.
 */
final readonly class LegalExportEmployeeMatch
{
    public function __construct(
        public string $uuid,
        public string $firstName,
        public string $lastName,
        public ?int $departmentId,
        public ?string $departmentName,
        public EmploymentStatus $status,
        public string $hiredAt,
        public ?string $terminatedAt,
        /** @var list<EmploymentPeriod> */
        public array $employmentPeriods = [],
    ) {}
}
