<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Support;

use App\Modules\Attendance\Http\Policy\ScanPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * El quiosco que esta detras del token, con sus dos identificadores.
 *
 * **El dispositivo no viaja en el cuerpo de la peticion y no es un descuido del
 * contrato.** `ScanRequest` no tiene campo `device_id`: si lo tuviera, cualquier
 * portador de un token podria atribuir un fichaje a otro quiosco, y con eso el
 * registro de **donde** se ficho —que es la mitad de lo que hace creible un
 * registro de presencia— dejaria de significar nada. El dispositivo se deduce
 * de quien autentica, que es lo unico que no se puede falsificar desde el
 * cliente.
 *
 * **Dos identificadores y no uno.** `id` es la clave interna que necesita la
 * clave foranea de `scan_events`; `uuid` es el identificador **publico** y es el
 * unico que puede aparecer en un log tecnico, en una metrica o en
 * `error_events` (regla dura 21, doc 02 §8.1). Se leen los dos de la misma fila
 * ya cargada, para no volver a la base de datos por cada fichaje.
 *
 * **Se identifica por la tabla y no por la clase**, igual que {@see ScanPolicy}
 * y por el mismo motivo: `Attendance` no puede importar el modelo `Device` de
 * `Identity` (doc 02 §1.6), y la tabla es lo estable — `scan_events.device_id`
 * es una clave foranea hacia ella. Funciona sin cambios con los tokens de
 * dispositivo que emite la tarea 1.5.
 */
final readonly class ScanningDevice
{
    private function __construct(
        public int $id,
        public string $uuid,
    ) {}

    /**
     * El quiosco autenticado en esta peticion.
     *
     * Solo se llama **despues** de la policy, que es quien garantiza que el
     * portador es un dispositivo. Por eso aqui un actor que no lo sea es un
     * fallo del programa —una ruta sin autorizar— y no una respuesta `403` mas:
     * fallar en voz alta es lo correcto cuando el orden de los controles se ha
     * roto.
     */
    public static function of(Request $request): self
    {
        $actor = $request->user();

        if (! $actor instanceof Model || $actor->getTable() !== ScanPolicy::DEVICES_TABLE) {
            throw new RuntimeException(
                'ScanningDevice::of() se ha llamado sin que la policy de escaneo haya autorizado la peticion.',
            );
        }

        $id = $actor->getKey();
        $uuid = $actor->getAttribute('uuid');

        if (! is_numeric($id) || ! is_string($uuid)) {
            throw new RuntimeException('El dispositivo autenticado no tiene identificador publico.');
        }

        return new self((int) $id, $uuid);
    }
}
