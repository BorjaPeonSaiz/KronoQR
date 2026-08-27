<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Cuanta gente de un centro todavia no puede fichar con tarjeta (RF-QR-08,
 * doc 02 §8.2).
 *
 * Es lo que alimenta las dos metricas de esta tarea, las dos etiquetadas por
 * centro porque quien tiene que actuar es la persona de RRHH de ese hotel:
 *
 * ```
 * credentials_pending_print{site}                 -> pendingPrint
 * employees_without_delivered_credential{site}    -> withoutDeliveredCredential
 * ```
 *
 * **La segunda debe llegar a cero antes del primer dia de cada incorporacion**
 * (§8.2). Es la unica metrica de este producto que mide un proceso humano y no
 * un sistema, y por eso incluye a quien tiene la tarjeta impresa pero sin
 * entregar: un PDF impreso que sigue en una bandeja no sirve de nada a las 06:00.
 */
final readonly class SiteCredentialCoverage
{
    public function __construct(
        public int $siteId,
        /** Nombre del centro. Es la etiqueta legible del panel; la metrica usa el identificador. */
        public string $siteName,
        /** Empleados de alta del centro. Es el denominador de las dos metricas. */
        public int $employees,
        /** Credenciales activas sin `printed_at`: esperan a pasar por la impresora. */
        public int $pendingPrint,
        /** Personas de alta que todavia no tienen una tarjeta entregada en la mano. */
        public int $withoutDeliveredCredential,
    ) {
        if ($siteId < 1) {
            throw new InvalidArgumentException('La cobertura de credenciales pertenece a un centro concreto.');
        }

        if ($employees < 0 || $pendingPrint < 0 || $withoutDeliveredCredential < 0) {
            throw new InvalidArgumentException('Un recuento de cobertura no puede ser negativo.');
        }

        if ($pendingPrint > $employees || $withoutDeliveredCredential > $employees) {
            throw new InvalidArgumentException('No puede faltarle la tarjeta a mas gente de la que hay de alta.');
        }
    }
}
