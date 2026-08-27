<?php

declare(strict_types=1);

namespace App\Modules\Product;

use App\Modules\Product\Infrastructure\Adapter\DbOperationalSettingsProvider;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo Product — configuracion de instalacion, perfiles de cumplimiento,
 * marca, licencia, diagnostico y soporte (doc 02 §1.6). Existe para que la
 * diferencia entre clientes sea dato y no codigo (regla dura 13, ADR-017).
 *
 * Ningun modulo lee esta configuracion directamente: recibe el valor ya
 * resuelto o un puerto tipado.
 *
 * **`OperationalSettingsProvider` se enlaza ya, aunque su tarea sea la 5.1.**
 * Sin el, el fichaje de la tarea 1.4 no tendria de donde sacar la ventana
 * anti-rebote de RF-AT-06 ni la duracion anomala de RN-08, y la unica
 * alternativa seria escribir 60 s y 12 h como constantes en PHP — que es
 * exactamente lo que la regla dura 14 prohibe. Lo que la 5.1 anade encima es la
 * **edicion desde el panel** y la auditoria de ese cambio; la lectura es esta.
 *
 * Enlaces todavia pendientes (tarea 5.1, ADR-025):
 *   - Shared\Application\Port\CompliancePolicyProvider -> DbCompliancePolicyProvider
 *   - Shared\Application\Port\BrandingProvider         -> DbBrandingProvider
 * Los dos adaptadores viven en Product/Infrastructure/Adapter/, que es donde
 * estan las tablas.
 */
final class ProductServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton por su memoria por peticion: el fichaje pide la
        // configuracion en cada escaneo, y a cincuenta por segundo (RNF-P-06)
        // eso serian cincuenta consultas por segundo a una tabla de cuatro
        // filas que cambia una vez al ano.
        $this->app->singleton(
            OperationalSettingsProvider::class,
            static fn (): DbOperationalSettingsProvider => new DbOperationalSettingsProvider(DB::connection()),
        );
    }
}
