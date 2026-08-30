<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * A quien afecta una incidencia, tal y como lo necesita la bandeja (RF-PA-05).
 *
 * **No es el modelo `Incident`, y por eso vive aqui y no en `Domain`.** El
 * dominio conoce a la persona por su `employeeUuid` y con eso le basta: la
 * severidad, el responsable y el estado no dependen de como se llame nadie. Esto
 * es lo que hace falta para **pintar una fila** —codigo y nombre para
 * reconocerla, departamento para agruparla—, y meterlo en el agregado lo
 * convertiria en una vista.
 *
 * `departmentId` no es decorativo: es lo que compara `ScopeGuard` antes de dejar
 * resolver una incidencia (RF-ID-03). `null` cuando la persona no tiene
 * departamento, que solo alcanza una cuenta sin restriccion.
 *
 * **El nombre viaja a la pantalla del panel autorizado y jamas a un log**
 * (regla dura 21). Lo que sale por la traza, la metrica y `audit_log` es el
 * `employeeUuid` de al lado.
 */
final readonly class IncidentSubject
{
    public function __construct(
        public string $employeeUuid,
        public string $employeeCode,
        public string $fullName,
        public ?int $departmentId,
        public ?string $departmentName,
    ) {}
}
