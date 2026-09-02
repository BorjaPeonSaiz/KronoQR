<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use DateTimeImmutable;

/**
 * La respuesta del `Shared/Application/Port/FeatureGate`: si
 * una funcionalidad accesoria esta disponible y, si no lo esta, por que y desde
 * cuando (**ADR-019**, RF-PD-05).
 *
 * ## Por que no basta con un booleano
 *
 * Porque ADR-019 exige degradacion **honesta** y su verificacion lo dice sin
 * rodeos: *«cada funcionalidad accesoria responde con el aviso de licencia y no
 * con un error generico»*. Un `false` produce inevitablemente un error generico,
 * porque quien lo recibe no tiene con que redactar otra cosa. Con el motivo y la
 * fecha, el borde puede escribir «los informes avanzados estan desactivados
 * porque la licencia caduco el 12/03/2026; el registro y la exportacion para la
 * Inspeccion siguen disponibles; renuevala para recuperarlos».
 *
 * ## `since` no es la fecha de la consulta
 *
 * Es el instante desde el que la funcionalidad dejo de estar disponible: la
 * caducidad de la licencia, o el inicio de vigencia todavia futuro. Va **nulo**
 * cuando no hay tal fecha —no hay clave, no verifica, o la funcionalidad no
 * entra en el plan—, y en ese caso el texto dice otra cosa en vez de inventar un
 * dia.
 */
final readonly class FeatureAvailability
{
    private function __construct(
        public Feature $feature,
        public bool $enabled,
        public ?FeatureRestriction $restriction,
        public ?DateTimeImmutable $since,
    ) {}

    public static function granted(Feature $feature): self
    {
        return new self($feature, true, null, null);
    }

    public static function denied(
        Feature $feature,
        FeatureRestriction $restriction,
        ?DateTimeImmutable $since = null,
    ): self {
        return new self($feature, false, $restriction, $since);
    }
}
