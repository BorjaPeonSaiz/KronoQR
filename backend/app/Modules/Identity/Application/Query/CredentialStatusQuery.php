<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Query;

/**
 * Los filtros del panel de estado de credenciales (RF-QR-08).
 *
 * **`pendingOnly` responde a la pregunta que el panel existe para responder.** El
 * doc 02 §5.5 la enuncia: *«RF-QR-08 existe para que RRHH vea de un vistazo quien
 * no puede fichar todavia. Sin el, el problema se descubre delante del quiosco a
 * las 06:00.»* Con la lista completa de un hotel de trescientas personas delante,
 * las cuatro que faltan no se ven; por eso `credentials:status --pending` es la
 * forma en que ese comando se usa de verdad.
 *
 * «Pendiente» aqui significa **todo el que todavia no puede fichar con tarjeta**:
 * sin credencial, pendiente de imprimir, pendiente de entregar y revocada. Es
 * exactamente lo que cuenta `employees_without_delivered_credential`.
 *
 * **`unattended` decide si la lectura deja asiento** (RS-05). Por omision es
 * `false`, de modo que el caso que se asume es el que audita: quien quiera una
 * lectura sin constancia tiene que pedirla, y al pedirla se ve en el codigo.
 */
final readonly class CredentialStatusQuery
{
    public function __construct(
        /** Centro, o `null` para toda la instalacion. */
        public ?int $siteId = null,
        /** Solo quien todavia no tiene una tarjeta entregada en la mano. */
        public bool $pendingOnly = false,
        /**
         * Nadie va a ver estas filas: la lectura la hace el planificador para
         * publicar los dos contadores del §8.2 y las filas nominales no salen
         * del proceso. Es la unica lectura de este panel que **no** es una
         * divulgacion, y por eso la unica que no escribe en `audit_log`.
         */
        public bool $unattended = false,
    ) {}
}
