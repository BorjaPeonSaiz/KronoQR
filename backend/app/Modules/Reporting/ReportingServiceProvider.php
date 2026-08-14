<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use Illuminate\Support\ServiceProvider;

/**
 * Modulo Reporting — proyecciones, consultas de lectura y exportaciones
 * (doc 02 §1.6). Depende de Shared y de eventos de otros modulos.
 *
 * daily_totals es una proyeccion reconstruible que se recalcula, nunca se
 * incrementa (regla dura 7, RN-06, ADR-007). Sus listeners llegan con la
 * tarea 1.9.
 */
final class ReportingServiceProvider extends ServiceProvider {}
