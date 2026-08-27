<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Support;

use App\Modules\Kiosk\Http\Policy\KioskPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * El quiosco que esta detras del token, con sus tres datos: clave interna, UUID
 * publico y **centro al que esta vinculado**.
 *
 * ## El centro sale de aqui y de ningun otro sitio
 *
 * Es lo que hace cierta la promesa del §7.3: *«`roster:read` devuelve solo el
 * minimo necesario **del centro al que esta vinculado el dispositivo**»*. Ninguno
 * de los dos endpoints de este modulo acepta `site_id` como parametro, y no es una
 * omision del contrato: si lo aceptaran, un token de quiosco robado en la puerta
 * de servicio de un hotel serviria para descargarse el padron de todos los demas
 * de la cadena. Un alcance que no se puede expresar no se puede saltar.
 *
 * ## Tres identificadores y por que cada uno
 *
 * - `id` es la clave interna, la que necesita el `UPDATE` del latido.
 * - `uuid` es el identificador **publico**, y el unico que puede aparecer en un
 *   log tecnico, en una metrica o en `error_events` (regla dura 21, §8.1).
 * - `siteId` es el alcance.
 *
 * Los tres se leen de la misma fila ya cargada por el guard: volver a la base de
 * datos por cada latido seria una consulta de mas cada minuto y por cada tablet.
 *
 * ## Se identifica por la tabla y no por la clase
 *
 * Igual que {@see KioskPolicy} y por el mismo motivo: `Kiosk` no puede importar el
 * modelo `Device` de `Identity` (§1.6), y la tabla es lo estable.
 */
final readonly class KioskDevice
{
    private function __construct(
        public int $id,
        public string $uuid,
        public int $siteId,
    ) {}

    /**
     * El quiosco autenticado en esta peticion.
     *
     * Solo se llama **despues** de la policy, que es quien garantiza que el
     * portador es un dispositivo. Por eso aqui un actor que no lo sea es un fallo
     * del programa —una ruta sin autorizar— y no una respuesta `403` mas: fallar
     * en voz alta es lo correcto cuando el orden de los controles se ha roto.
     */
    public static function of(Request $request): self
    {
        $actor = $request->user();

        if (! $actor instanceof Model || $actor->getTable() !== KioskPolicy::DEVICES_TABLE) {
            throw new RuntimeException(
                'KioskDevice::of() se ha llamado sin que la policy del quiosco haya autorizado la peticion.',
            );
        }

        $id = $actor->getKey();
        $uuid = $actor->getAttribute('uuid');
        $siteId = $actor->getAttribute('site_id');

        if (! is_numeric($id) || ! is_string($uuid) || ! is_numeric($siteId)) {
            throw new RuntimeException('El dispositivo autenticado no esta vinculado a ningun centro.');
        }

        return new self((int) $id, $uuid, (int) $siteId);
    }
}
