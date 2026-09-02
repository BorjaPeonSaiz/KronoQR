<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\OperationalSettings;

/**
 * Entrega los umbrales **operativos** del centro ya resueltos (regla dura 14).
 *
 * Dos puertos y no uno porque son dos fuentes distintas: los de
 * {@see CompliancePolicyProvider} los fija la jurisdiccion y viven en
 * `compliance_profiles`; estos los fija el hotel y viven en
 * `installation_settings` (doc 01 §4, nota sobre RN-08 y RN-16). Meter la
 * duracion anomala de tramo en el perfil de cumplimiento seria un error de
 * fondo, y ademas ese perfil ni siquiera tiene columna para ella.
 *
 * **Vive en Shared** (ADR-025) porque lo consumen Attendance —RN-08— y Kiosk
 * —RF-AT-10, la tolerancia de desfase de reloj—. Su adaptador es
 * `Product/Infrastructure/Adapter/DbOperationalSettingsProvider` (tarea 5.1).
 */
interface OperationalSettingsProvider
{
    /**
     * Configuracion operativa vigente para el centro indicado.
     *
     * El adaptador resuelve la cascada de `installation_settings`, que desde la
     * tarea 5.1 tiene **dos escalones**: la fila de la instalacion si existe, y
     * si no el valor por defecto del catalogo en codigo. El ambito `site` se
     * retiro con la migracion de contraccion `2026_09_05_100000` porque hay
     * exactamente un centro por instalacion (ADR-040): un escalon que siempre
     * resuelve al mismo sitio no es una cascada.
     *
     * `$siteId` se conserva en la firma porque el nucleo lo tiene a mano
     * —`shift_entries` sigue llevando `site_id`— y quitarlo obligaria a tocar
     * `Attendance` y `Kiosk` a cambio de nada.
     *
     * **Nunca falla por falta de configuracion.** Una instalacion sin ninguna
     * fila recibe los valores de serie del producto y ficha con normalidad
     * (regla dura 19): una fila borrada por descuido no puede dejar a un centro
     * sin poder fichar.
     */
    public function forSite(int $siteId): OperationalSettings;
}
