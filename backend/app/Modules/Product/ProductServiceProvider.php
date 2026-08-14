<?php

declare(strict_types=1);

namespace App\Modules\Product;

use Illuminate\Support\ServiceProvider;

/**
 * Modulo Product — configuracion de instalacion, perfiles de cumplimiento,
 * marca, licencia, diagnostico y soporte (doc 02 §1.6). Existe para que la
 * diferencia entre clientes sea dato y no codigo (regla dura 13, ADR-017).
 *
 * Ningun modulo lee esta configuracion directamente: recibe el valor ya
 * resuelto o un puerto tipado. Enlaces pendientes (tarea 5.1, ADR-025):
 *   - Shared\Application\Port\CompliancePolicyProvider   -> DbCompliancePolicyProvider
 *   - Shared\Application\Port\OperationalSettingsProvider -> DbOperationalSettingsProvider
 *   - Shared\Application\Port\BrandingProvider            -> DbBrandingProvider
 * Los tres adaptadores viven en Product/Infrastructure/Adapter/, que es donde
 * estan las tablas.
 */
final class ProductServiceProvider extends ServiceProvider {}
