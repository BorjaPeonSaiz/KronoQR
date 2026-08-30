<?php

declare(strict_types=1);

namespace App\Modules\Product;

use App\Modules\Product\Infrastructure\Adapter\DbCompliancePolicyProvider;
use App\Modules\Product\Infrastructure\Adapter\DbOperationalSettingsProvider;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
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
 * **`CompliancePolicyProvider` se enlaza desde la tarea 2.6**, y por el mismo
 * motivo: la deteccion de RN-10, RN-11 y RN-12 necesita el descanso minimo, la
 * jornada ordinaria y el tramo continuo maximo, y la unica alternativa era
 * escribir 12 h, 9 h y 6 h en PHP. La tarea 5.2 anade encima la edicion desde el
 * panel y la auditoria del cambio; la lectura es esta.
 *
 * Enlaces todavia pendientes (tarea 5.1, ADR-025):
 *   - Shared\Application\Port\BrandingProvider -> DbBrandingProvider
 * Su adaptador vivira tambien en Product/Infrastructure/Adapter/, que es donde
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

        // Los umbrales legales (RF-PD-07). Singleton por la misma razon: la vista
        // de cumplimiento los pedira una vez por jornada de un informe, y son una
        // fila que cambia cuando cambia el convenio.
        $this->app->singleton(
            CompliancePolicyProvider::class,
            static fn (): DbCompliancePolicyProvider => new DbCompliancePolicyProvider(DB::connection()),
        );
    }
}
