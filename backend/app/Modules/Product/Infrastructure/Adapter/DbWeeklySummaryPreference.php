<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Application\Port\WeeklySummaryPreference;

/**
 * Si la instalacion quiere el resumen semanal por correo, resuelto desde
 * `installation_settings` (**RF-PR-05**, `WEEKLY_SUMMARY_EMAIL`, tarea 3.12).
 *
 * Hermano de {@see DbKioskServiceCodeProvider} y de
 * {@see DbOperationalSettingsProvider}: misma cascada —fila de la instalacion,
 * y si no el valor de serie del catalogo— y la misma razon para existir.
 * `Reporting` no puede importar `Product` (doc 02 §1.6), asi que el puerto vive
 * en `Shared` y el adaptador aqui, que es donde se sabe leer la tabla.
 *
 * ## Aqui SI se deja subir el fallo, al contrario que en el latido
 *
 * El codigo de servicio se traga cualquier excepcion porque una tablet no puede
 * quedarse sin latido por una fila ilegible (regla dura 19). Esto corre en un
 * comando programado de madrugada, sin nadie delante y sin ningun fichaje
 * esperando: si la configuracion no se puede leer, lo correcto es que la pasada
 * falle con su codigo de salida y que `scheduler.command_failed` lo diga, en
 * lugar de decidir por su cuenta que el cliente no queria el correo. Un resumen
 * que deja de enviarse en silencio es indistinguible de uno apagado a proposito.
 *
 * **Y no compara contra el valor de serie**: compara con `enabled`, que es la
 * unica traduccion del `choice` a un booleano y esta en un solo sitio. Cualquier
 * otra cosa —una fila corrupta que la cascada haya descartado, un valor futuro—
 * vale `false`, que es el lado seguro: sin correo no sale ningun dato personal
 * de la instalacion.
 */
final readonly class DbWeeklySummaryPreference implements WeeklySummaryPreference
{
    public function __construct(private GetSettingsHandler $settings) {}

    public function weeklySummaryEnabled(): bool
    {
        return $this->settings->handle()->text(SettingKey::WEEKLY_SUMMARY_EMAIL) === 'enabled';
    }
}
