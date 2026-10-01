<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

/**
 * Que decide la politica de rotacion ante un latido (RF-ID-04, ADR-044).
 */
enum DeviceTokenRotationOutcome: string
{
    /** No toca emitir nada. Puede haber, aun asi, tokens relevados que retirar. */
    case NONE = 'none';

    /**
     * El token que firmo ha pasado el umbral: se emite su relevo y el firmante
     * entra en solape.
     */
    case ROTATE = 'rotate';

    /**
     * El token que firmo ya estaba en solape y su relevo no se ha usado nunca:
     * la respuesta que lo llevaba se perdio. Se retira ese relevo y se emite
     * otro, **sin alargar el solape** del firmante.
     */
    case REDELIVER = 'redeliver';
}
