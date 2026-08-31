<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Command;

/**
 * Intencion de cambiar uno o varios campos del perfil de cumplimiento
 * (RF-PD-07).
 *
 * `values` viene indexado por el nombre del campo tal como lo declara
 * `ComplianceProfileField` y lo nombra el contrato (`min_rest_hours`). No se
 * tipa mas fino a proposito: la validacion vive en el dominio y tiene que valer
 * igual para la consola y para el instalador, no solo para lo que haya filtrado
 * un `FormRequest`.
 *
 * **El autor no se declara en el cuerpo de la peticion**, se toma de la sesion:
 * aceptarlo permitiria firmar un cambio de umbral legal a nombre de otra
 * persona. `null` es la consola, que se identifica en el asiento como actor de
 * sistema.
 */
final readonly class UpdateComplianceProfileCommand
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public array $values,
        public ?int $actorUserId,
    ) {}
}
