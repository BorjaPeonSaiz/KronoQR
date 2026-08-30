<?php

declare(strict_types=1);

use App\Modules\Attendance\AttendanceServiceProvider;
use App\Modules\Compliance\ComplianceServiceProvider;
use App\Modules\Compliance\RetentionServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Kiosk\KioskServiceProvider;
use App\Modules\Product\ProductServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Shared\SharedServiceProvider;
use App\Modules\Workforce\WorkforceServiceProvider;
use App\Providers\AppServiceProvider;

/*
 * Los ocho modulos del doc 02 §1.6, registrados explicitamente y en orden de
 * dependencia: Shared primero, porque los demas resuelven sus puertos.
 *
 * La lista no se descubre por convencion a proposito: un modulo nuevo es una
 * decision de arquitectura, y aparecer aqui es lo que la hace visible.
 */
return [
    AppServiceProvider::class,

    SharedServiceProvider::class,
    AttendanceServiceProvider::class,
    ComplianceServiceProvider::class,
    /*
     * Segundo proveedor de `Compliance` y no un modulo nuevo (tarea 2.10): la
     * retencion registra la MISMA clase con DOS conexiones y dos roles -el de la
     * aplicacion para contar, el de mantenimiento para soltar una particion,
     * ADR-033-, y ese porque se lee mejor junto que repartido en un `register()`
     * de trescientas lineas. Si una revision prefiere un proveedor por modulo, su
     * contenido se mueve tal cual a `ComplianceServiceProvider`.
     */
    RetentionServiceProvider::class,
    WorkforceServiceProvider::class,
    IdentityServiceProvider::class,
    ReportingServiceProvider::class,
    KioskServiceProvider::class,
    ProductServiceProvider::class,
];
