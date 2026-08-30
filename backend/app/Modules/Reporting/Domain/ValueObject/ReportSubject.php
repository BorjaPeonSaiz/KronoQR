<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use InvalidArgumentException;

/**
 * De quien —o de que— habla una fila del informe (**RF-IN-01**, RF-IN-02).
 *
 * Un solo tipo para los tres agrupamientos en lugar de tres clases, porque la
 * fila es la misma y lo unico que cambia es a quien se le atribuye. Tres clases
 * habrian obligado a tres `Resource` y a tres esquemas del contrato para
 * describir la misma tabla.
 *
 * **`employeeUuid` es el identificador publico** y la clave interna no aparece
 * nunca (doc 01 §5.5). `departmentId` si es la clave interna, igual que en el
 * resto de la API: `departments` no tiene UUID y su numero no revela nada sobre
 * ninguna persona.
 *
 * **El nombre completo va aqui y no en el log** (regla dura 21): esto es una
 * respuesta al panel, con control de acceso y su asiento en `audit_log`; el log
 * tecnico de la generacion lleva `employee_uuid` y ni un nombre.
 */
final readonly class ReportSubject
{
    private function __construct(
        public ReportGrouping $kind,
        public ?string $employeeUuid,
        public ?string $employeeCode,
        public ?string $fullName,
        public ?int $departmentId,
        /** Nombre del departamento, o del centro cuando la fila es del centro. */
        public ?string $label,
    ) {}

    public static function employee(
        string $uuid,
        string $employeeCode,
        string $fullName,
        ?int $departmentId,
        ?string $departmentName,
    ): self {
        if ($uuid === '') {
            throw new InvalidArgumentException('Una fila de empleado necesita su UUID publico.');
        }

        return new self(ReportGrouping::Employee, $uuid, $employeeCode, $fullName, $departmentId, $departmentName);
    }

    /**
     * `null` en los dos argumentos es el cubo de **quien no tiene departamento**,
     * y tiene que existir: sin el, la suma de los departamentos no cuadraria con
     * el total del centro y nadie sabria por que faltan horas. Es el mismo
     * criterio que la union del gauge de turnos abiertos.
     */
    public static function department(?int $id, ?string $name): self
    {
        return new self(ReportGrouping::Department, null, null, null, $id, $name);
    }

    public static function site(string $name): self
    {
        return new self(ReportGrouping::Site, null, null, null, null, $name);
    }
}
