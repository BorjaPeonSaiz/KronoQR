<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

/**
 * Una incidencia que no se puede construir porque no describiria nada
 * accionable (RF-PR-01).
 *
 * Las cuatro guardas responden a la misma pregunta: **¿podria alguien trabajar
 * esta fila?** Sin empleado no hay a quien mirar; sin centro no se sabe en que
 * zona horaria ocurrio la jornada (RN-05); con una fecha imposible la bandeja no
 * puede ordenarla; y con un instante que no es UTC el «tiempo hasta resolver»
 * del doc 01 §9.2 sale desplazado una hora dos veces al año (regla dura 3).
 */
final class InvalidIncident extends ComplianceDomainException
{
    public static function withoutEmployee(): self
    {
        return new self('Una incidencia sin empleado no se puede asignar ni revisar.');
    }

    public static function withoutSite(int $siteId): self
    {
        return new self('El centro de una incidencia es un identificador positivo, y ha llegado '.$siteId.'.');
    }

    public static function withInvalidWorkDate(string $workDate): self
    {
        return new self('«'.$workDate.'» no es una fecha de jornada valida en formato Y-m-d.');
    }

    public static function withInvalidAssignee(int $userId): self
    {
        return new self(
            'El responsable de una incidencia es un identificador positivo de `users`, y ha llegado '.$userId.'. '
            .'Sin responsable asignado se usa null, que es un estado legitimo: la incidencia queda sin asignar.'
        );
    }

    public static function withNonUtcDetectionInstant(string $detectedAt): self
    {
        return new self('El momento de deteccion se guarda en UTC (regla dura 3) y ha llegado «'.$detectedAt.'».');
    }
}
