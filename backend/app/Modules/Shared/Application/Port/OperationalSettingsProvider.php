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
     * El adaptador resuelve la cascada de `installation_settings`: el valor de
     * ambito `site` si existe, y si no el de ambito `installation`.
     */
    public function forSite(int $siteId): OperationalSettings;
}
