<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Infrastructure\Adapter\DbLocalePolicyProvider;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use App\Support\Locale\NegotiableLocales;
use Illuminate\Support\Facades\DB;

/**
 * Deja la instalacion hablando el idioma que se le diga (RF-PD-01, RF-PD-08).
 *
 * ## Por que hace falta desde la tarea 5.8
 *
 * Antes, «el idioma de la instalacion» era `app.locale`, asi que una prueba lo
 * cambiaba con `App::setLocale('en')`. Ahora sale de `installation_settings`
 * —el cliente lo cambia desde el panel, queda auditado y rige en la peticion
 * siguiente, sin reiniciar (regla dura 13)—, y `App::setLocale()` ya no lo
 * decide: lo pisa `NegotiateLocale` en cada peticion con lo que diga la fila.
 *
 * Eso es exactamente lo que se queria, y obliga a que las pruebas que hablan del
 * idioma **de la instalacion** —el separador del CSV que abrira Excel, el idioma
 * de un documento— escriban la fila en vez de tocar la configuracion del proceso.
 * Las que hablan del idioma **de la peticion** siguen mandando `Accept-Language`,
 * que es lo que manda un navegador.
 *
 * ## Escribe la fila a mano
 *
 * Con `DB::table()` y no por la API: quien la escribe de verdad es un
 * administrador desde el panel, y montar esa sesion en cada prueba de informes
 * añadiria un usuario, un token y dos peticiones para fijar un dato de contexto.
 */
final class InstallationLocale
{
    /**
     * @param  list<string>  $available  por defecto, los dos que trae el producto
     */
    public static function set(string $default, array $available = ['es', 'en']): void
    {
        self::write('LOCALE_DEFAULT', json_encode($default, JSON_THROW_ON_ERROR));
        self::write('LOCALE_AVAILABLE', json_encode($available, JSON_THROW_ON_ERROR));

        // Los proveedores memorizan por peticion, y en la suite el contenedor
        // sobrevive de una llamada a la siguiente dentro de la misma prueba.
        foreach ([LocalePolicyProvider::class, NegotiableLocales::class, DbLocalePolicyProvider::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
    }

    private static function write(string $key, string $json): void
    {
        DB::table('installation_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $json, 'updated_at' => '2026-01-01 00:00:00+00'],
        );
    }
}
