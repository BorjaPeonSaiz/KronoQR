<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Application\Port\KioskServiceCodeProvider;
use Throwable;

/**
 * El codigo de servicio del quiosco, resuelto desde `installation_settings`
 * (**RF-KI-08**, tarea 3.3, regla dura 13, ADR-025).
 *
 * Hermano de {@see DbOperationalSettingsProvider}: mismo modulo, misma cascada
 * —fila de la instalacion, y si no el valor de serie del catalogo— y la misma
 * razon para existir. `Kiosk` no puede importar `Product` (doc 02 §1.6), asi que
 * el puerto vive en `Shared` y el adaptador aqui, que es donde se sabe leer la
 * tabla.
 *
 * ## Esto corre DENTRO del latido, y eso manda sobre todo lo demas
 *
 * El latido es la unica senal de que una tablet sigue viva. Si esta lectura
 * lanzara —una base de datos que no responde, una fila ilegible— el quiosco
 * recibiria un `500` y reintentaria en bucle **justo cuando la instalacion ya
 * tiene un problema**, y el panel de salud perderia al mismo tiempo la
 * informacion con la que se diagnostica (regla dura 19). Por eso el fallo se
 * traga y se devuelve `null`: sin huella, la pantalla de diagnostico de la
 * tablet se abre sin codigo, que es el mismo estado que el de una instalacion
 * que todavia no ha configurado ninguno.
 *
 * **El silencio no deja el problema invisible**: la sonda `kiosk.service_code`
 * de `product:doctor` lo dice desde el servidor, y la configuracion que no se
 * pudo resolver aparece ya en `settings.invalid_keys`.
 *
 * ## Sin memoria por peticion, al contrario que los umbrales operativos
 *
 * Aquellos se piden en **cada** escaneo y por eso se memorizan; este se pide una
 * vez por latido, que es una vez por minuto y por quiosco. Una cache aqui seria
 * un sitio mas donde el codigo vive en memoria a cambio de nada.
 */
final readonly class DbKioskServiceCodeProvider implements KioskServiceCodeProvider
{
    public function __construct(private GetSettingsHandler $settings) {}

    public function serviceCode(): ?string
    {
        try {
            $code = $this->settings->handle()->get(SettingKey::KIOSK_SERVICE_CODE)->asText();
        } catch (Throwable) {
            // Ver el docblock: un latido nunca se pierde por esto. No se
            // registra el fallo aqui —seria una linea por minuto y por quiosco
            // mientras dure la averia— porque quien lo dice es `doctor`.
            return null;
        }

        // La cadena vacia es el valor de serie y significa «sin codigo». Se
        // normaliza a `null` para que el consumidor tenga un unico caso que
        // tratar y no dos que significan lo mismo.
        return $code === '' ? null : $code;
    }
}
