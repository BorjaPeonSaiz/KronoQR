<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Shared\Domain\ValueObject\CredentialResolution;

/**
 * De un payload QR firmado al empleado que hay detras, o a un motivo de
 * rechazo (RF-QR-01, RF-QR-02, RF-QR-03).
 *
 * **Lo declara el nucleo y lo implementa `Identity`** (ADR-025), que es donde
 * viven la tabla `credentials` y las claves de firma. La arista va del satelite
 * al nucleo: Attendance no sabe quien le sirve la credencial, y por eso rotar
 * el esquema de firma no toca el fichaje.
 *
 * Habla en un tipo de `Shared` y en un escalar porque el adaptador de Identity
 * solo puede alcanzar `Attendance\Application\Port`, nunca su `Domain`
 * (ADR-025, restriccion 2).
 *
 * **Dos obligaciones del adaptador que no son opcionales:**
 *
 * 1. **Tiempo constante** (RS-03, regla dura 17). Un rechazo por firma invalida
 *    no puede tardar menos que uno por credencial revocada: la diferencia de
 *    tiempo revela lo mismo que el mensaje que no se envia, y con ella se puede
 *    sondear que tokens existen.
 * 2. **Verificar la firma antes de resolver** el empleado (RF-QR-02). Consultar
 *    primero y verificar despues convierte el endpoint en un oraculo de
 *    existencia.
 */
interface CredentialResolver
{
    /**
     * @param  string  $qrPayload  El payload leido del QR, en formato
     *                             `FH1.<key_id>.<token>.<sig>` (doc 02 §5.1).
     *                             Opaco: sin PII y sin identificadores
     *                             secuenciales.
     */
    public function resolve(string $qrPayload): CredentialResolution;
}
