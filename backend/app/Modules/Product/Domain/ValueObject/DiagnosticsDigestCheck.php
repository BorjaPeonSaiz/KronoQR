<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Lo que dice la huella de un paquete de diagnostico releido del disco
 * (`product:diagnostics --verify`, PR16).
 *
 * Tres respuestas y no un booleano porque el paquete de `schema_version: 1`
 * —anterior a la 2.3.0— tiene una huella que cubre solo las secciones: puede
 * estar intacto y aun asi llevar un `anonymized` cambiado a mano. Quien lo
 * verifica tiene que saberlo, no recibir el mismo «integro» que con la `2`.
 */
enum DiagnosticsDigestCheck: string
{
    /** `schema_version: 2`: la huella cubre las secciones y el manifiesto. */
    case Intact = 'intact';

    /** `schema_version: 1`: la huella cubre las secciones, no el manifiesto. */
    case IntactWithoutManifest = 'intact_without_manifest';

    /** No coincide, falta, o la version no es ninguna de las conocidas. */
    case Mismatch = 'mismatch';

    public function matches(): bool
    {
        return $this !== self::Mismatch;
    }
}
