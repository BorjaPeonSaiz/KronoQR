<?php

declare(strict_types=1);

namespace App\Modules\Compliance;

use Illuminate\Support\ServiceProvider;

/**
 * Modulo Compliance — auditoria, incidencias, retencion y exportacion legal
 * (doc 02 §1.6). Depende de Shared y reacciona a eventos de Attendance.
 *
 * Nunca llama a Attendance: se suscribe a sus eventos de dominio. Los
 * listeners y su registro llegan con la tarea 2.2.
 */
final class ComplianceServiceProvider extends ServiceProvider {}
