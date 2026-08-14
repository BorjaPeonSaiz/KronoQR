<?php

declare(strict_types=1);

namespace App\Modules\Workforce;

use Illuminate\Support\ServiceProvider;

/**
 * Modulo Workforce — empleados, departamentos, centros, contratos y ausencias
 * (doc 02 §1.6). Depende de Shared y de Attendance/Application/Port, cuyos
 * puertos implementa.
 *
 * Enlaces pendientes (tarea 1.6, ADR-025):
 *   - Attendance\Application\Port\EmployeeDirectory -> EloquentEmployeeDirectory
 *   - Attendance\Application\Port\SiteCalendar      -> EloquentSiteCalendar
 * Ambos adaptadores viven en Workforce/Infrastructure/Adapter/, que es donde
 * estan las tablas, y devuelven objetos de valor de Shared: nunca un modelo
 * Eloquent ni una entidad de Workforce (ADR-025, restriccion 2).
 */
final class WorkforceServiceProvider extends ServiceProvider {}
