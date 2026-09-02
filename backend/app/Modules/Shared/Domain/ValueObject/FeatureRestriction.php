<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Por que una funcionalidad accesoria no esta disponible (**ADR-019**: la
 * degradacion tiene que ser **honesta**).
 *
 * «Honesta» significa que el sistema dice **que** esta degradado, **desde
 * cuando** y **que hacer**, no que se comporte de forma silenciosamente peor.
 * Este enum es el «por que»; la fecha viaja aparte en
 * {@see FeatureAvailability} y el «que hacer» lo compone la capa que habla el
 * idioma del cliente (`lang/{es,en}/license.php`).
 *
 * **Cinco motivos y no uno**, porque la accion siguiente es distinta en cada
 * uno: renovar, activar la clave que ya se recibio, pedir una clave nueva al
 * fabricante, esperar a la fecha de inicio o ampliar el plan. Un unico
 * «licencia no valida» obligaria a llamar por telefono para saber cual de las
 * cinco.
 *
 * **Ninguno de estos motivos alcanza jamas al registro legal** (regla dura 15):
 * solo se pregunta por un {@see Feature}, y en ese enum no hay ningun caso del
 * conjunto legal.
 */
enum FeatureRestriction: string
{
    /** No hay ninguna clave activada. Instalacion recien puesta en marcha, o clave nunca introducida. */
    case LicenseAbsent = 'license_absent';

    /**
     * Hay clave, pero no verifica: formato roto, firma alterada, otro emisor, o
     * esta compilacion no lleva clave publica del fabricante.
     *
     * El detalle tecnico esta en `GET /api/v1/license` y en `license:show`; aqui
     * basta con que la accion siguiente sea la misma: pedir una clave nueva.
     */
    case LicenseUnverifiable = 'license_unverifiable';

    /** La clave verifica pero su vigencia todavia no ha empezado (`valid_from` futuro). */
    case LicenseNotYetValid = 'license_not_yet_valid';

    /** La clave verifica y su vigencia ya termino. Es el caso que gobierna ADR-019. */
    case LicenseExpired = 'license_expired';

    /**
     * La licencia esta vigente y **esta funcionalidad no esta en el plan**.
     *
     * No es una degradacion: es lo que el cliente no contrato. Se distingue de
     * las otras cuatro porque renovar no lo arregla —hay que ampliar el plan— y
     * porque no lleva fecha «desde cuando»: nunca estuvo.
     */
    case NotInPlan = 'not_in_plan';
}
