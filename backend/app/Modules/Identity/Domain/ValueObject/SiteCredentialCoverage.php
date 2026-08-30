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
 *
 * **Durante una rotacion de clave hay un tercer recuento** (RF-QR-07, tarea
 * 2.12): cuanta gente sigue fichando con una tarjeta firmada por la clave
 * saliente. No cabe en las dos anteriores porque esas personas **si pueden
 * fichar** —el solape existe justo para eso—, y contarlas ahi dispararia una
 * alerta que no corresponde a ningun problema. Es su propio indicador, y su
 * bajada hasta cero es el avance de la reimpresion:
 *
 * ```
 * credentials_pending_reprint{site,key_id}        -> pendingReprint
 * ```
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
        /** La clave saliente de una rotacion en curso, o `null` fuera de una rotacion. */
        public ?string $retiringKeyId = null,
        /** Personas cuya tarjeta en uso sigue firmada con esa clave saliente. */
        public int $pendingReprint = 0,
    ) {
        if ($siteId < 1) {
            throw new InvalidArgumentException('La cobertura de credenciales pertenece a un centro concreto.');
        }

        if ($employees < 0 || $pendingPrint < 0 || $withoutDeliveredCredential < 0 || $pendingReprint < 0) {
            throw new InvalidArgumentException('Un recuento de cobertura no puede ser negativo.');
        }

        if ($pendingPrint > $employees || $withoutDeliveredCredential > $employees || $pendingReprint > $employees) {
            throw new InvalidArgumentException('No puede faltarle la tarjeta a mas gente de la que hay de alta.');
        }
    }
}
