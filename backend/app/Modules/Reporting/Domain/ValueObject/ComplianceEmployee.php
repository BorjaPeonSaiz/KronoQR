<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * A quien afecta un hallazgo de cumplimiento, con lo justo para que la pantalla
 * se pueda trabajar (esquema `IncidentEmployee` del contrato).
 *
 * **El nombre viaja a la pantalla y nunca al log.** El identificador publico es
 * el unico que aparece en un log tecnico o en `audit_log` (regla dura 21); el
 * nombre esta aqui porque quien mira la vista de cumplimiento esta autorizado a
 * verlo y porque un aviso sobre «0199f0c2-…» no lo defiende nadie ante un
 * empleado.
 *
 * **Los apellidos van aparte del nombre completo** aunque el contrato solo
 * publique `full_name`: el orden de la respuesta es «apellidos, nombre», y
 * deducirlo partiendo una cadena ya compuesta ordenaria mal a quien tiene dos
 * apellidos o un nombre compuesto. Quien compone `full_name` es el adaptador,
 * una sola vez.
 */
final readonly class ComplianceEmployee
{
    public function __construct(
        /** Identificador **publico** (UUID v7). */
        public string $uuid,
        /** Codigo opaco impreso en la tarjeta (RF-ID-06). */
        public string $employeeCode,
        public string $firstName,
        public string $lastName,
        /** `null` para quien no tiene departamento, que es un estado legitimo. */
        public ?int $departmentId,
        public ?string $departmentName,
    ) {}

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /**
     * Clave de ordenacion estable: apellidos, nombre y, en empate, el
     * identificador.
     *
     * El desempate por UUID no es decorativo: dos personas con el mismo nombre y
     * apellidos existen, y sin el, dos ejecuciones de la misma consulta podrian
     * devolverlas en distinto orden y quien compara dos capturas de pantalla
     * creeria que algo ha cambiado.
     */
    public function sortKey(): string
    {
        return mb_strtolower($this->lastName)."\x1F".mb_strtolower($this->firstName)."\x1F".$this->uuid;
    }
}
