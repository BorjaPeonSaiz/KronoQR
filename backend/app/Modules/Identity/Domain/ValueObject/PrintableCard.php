<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;

/**
 * Una tarjeta lista para dibujar: el payload firmado y lo que va impreso a su
 * lado (RF-QR-04).
 *
 * **Existe para que el token no viaje suelto.** El renderizador necesita el
 * payload —es lo que hay dentro del QR— y necesita el nombre, el departamento y
 * el centro. Pasarlos como cuatro argumentos sueltos deja abierta la puerta a
 * que alguien anada un quinto: por ejemplo, el `secret_hash`, o el token en
 * claro por separado «para depurar». Con un objeto, lo que la plantilla puede
 * pintar es exactamente esta lista y ninguna otra cosa.
 *
 * **Vive el tiempo de una peticion y se olvida.** El payload de dentro es el
 * unico sitio del proceso donde el token existe en claro (ADR-034). No se
 * serializa, no se guarda, no viaja en la carga util de ningun trabajo en cola y
 * **no se escribe en ningun log** (regla dura 21 y doc 02 §5.2): quien lo tenga
 * puede fichar por su dueno.
 *
 * **La marca del cliente no esta aqui** (regla dura 13, ADR-017). El logotipo y
 * los colores son configuracion de la instalacion (RF-PD-08, tarea 5.8) y los
 * recibe la plantilla por su lado: no son datos de esta tarjeta ni de esta
 * persona, y meterlos aqui obligaria a que el caso de uso los resolviera.
 */
final readonly class PrintableCard
{
    public function __construct(
        /** UUID publico de la credencial. No se imprime: sirve para correlacionar. */
        public string $credentialUuid,
        /** El contenido del QR: `FH1.<key_id>.<token>.<sig>`. Nunca a un log. */
        public QrPayload $payload,
        /** Nombre, departamento y centro del titular (RF-QR-04). */
        public EmployeeCardProfile $holder,
    ) {}
}
