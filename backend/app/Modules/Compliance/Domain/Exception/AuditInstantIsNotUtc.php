<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

use DateTimeImmutable;

/**
 * Regla dura 3: la cadena se calcula sobre el instante **en UTC**, nunca sobre
 * una representacion local.
 *
 * Por que es un error y no una conversion silenciosa: si el escritor aceptara
 * `2026-03-29 03:00:00+02:00` y lo normalizara, dos entradas del mismo instante
 * escritas desde husos distintos producirian el mismo hash y nadie notaria que
 * alguien esta escribiendo con la zona equivocada. Convertir esconde el fallo;
 * rechazar lo expone en el sitio donde se comete.
 */
final class AuditInstantIsNotUtc extends ComplianceDomainException
{
    public static function forField(string $field, DateTimeImmutable $instant): self
    {
        return new self(sprintf(
            'La entrada de auditoria recibio «%s» en %s, que no es UTC. Regla dura 3: todo instante se almacena y se encadena en UTC.',
            $field,
            $instant->format(DateTimeImmutable::ATOM),
        ));
    }
}
