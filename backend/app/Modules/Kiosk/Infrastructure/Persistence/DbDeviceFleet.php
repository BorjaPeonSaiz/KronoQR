<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Infrastructure\Persistence;

use App\Modules\Kiosk\Application\Port\DeviceFleet;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La telemetria de `devices`, escrita con el constructor de consultas
 * (doc 01 §5.5, RF-PA-07).
 *
 * ## Por que aqui y no en `Identity`
 *
 * Porque `devices` es de este modulo: el doc 01 §5.5 lo dice con todas las letras
 * —*«`Device` es raiz de agregado en `Kiosk`, y `Identity` emite y revoca su
 * token»*— y el §1.6 le atribuye a `Kiosk` «dispositivos, emparejamiento,
 * sincronizacion de lotes y telemetria». `Identity` tiene su propio modelo sobre
 * la misma tabla para lo suyo, que es el token: son dos responsabilidades sobre
 * una fila, no dos dueños.
 *
 * ## Por que el constructor de consultas y no un modelo Eloquent
 *
 * Porque un segundo modelo `Device` sobre la misma tabla —con sus `fillable`, sus
 * `casts` y sus `hidden`— seria una segunda definicion de la fila que habria que
 * mantener sincronizada con la de `Identity` sin que nada lo verifique. Aqui se
 * tocan tres columnas de telemetria y ninguna mas: para eso, un `UPDATE`
 * explicito dice mas y esconde menos.
 *
 * ## Una sola sentencia
 *
 * Sin leer antes para escribir despues. No hay nada que decidir con el valor
 * anterior —los tres campos se sustituyen— y un `SELECT` previo seria una
 * condicion de carrera con dos quioscos... que no existe, porque solo un
 * dispositivo late por si mismo. Pero seguiria siendo una consulta de mas en un
 * endpoint que se llama cada minuto por cada tablet del hotel.
 *
 * **Un `deviceId` que no existe no escribe nada y no falla.** No puede ocurrir —el
 * identificador sale de un token de Sanctum, que cuelga de la propia fila— y si
 * ocurriera seria un dispositivo borrado a mitad de peticion: convertirlo en un
 * `500` no arreglaria nada y dejaria al quiosco reintentando un latido.
 */
final readonly class DbDeviceFleet implements DeviceFleet
{
    public function recordHeartbeat(
        int $deviceId,
        string $appVersion,
        int $pendingQueueSize,
        DateTimeImmutable $seenAt,
    ): void {
        DB::table('devices')
            ->where('id', $deviceId)
            ->update([
                'last_seen_at' => $seenAt->format('Y-m-d H:i:s.uP'),
                'app_version' => $appVersion,
                'pending_queue_size' => $pendingQueueSize,
                'updated_at' => $seenAt->format('Y-m-d H:i:s.uP'),
            ]);
    }
}
