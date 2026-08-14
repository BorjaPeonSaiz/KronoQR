<?php

declare(strict_types=1);

namespace App\Modules\Kiosk;

use Illuminate\Support\ServiceProvider;

/**
 * Modulo Kiosk — dispositivos, emparejamiento, sincronizacion de lotes y
 * telemetria (doc 02 §1.6). Depende de Shared y de Attendance via caso de uso
 * publico: Kiosk usa el fichaje, no lo sirve.
 *
 * El quiosco nunca bloquea al empleado (regla dura 19): encola, confirma
 * localmente y genera una incidencia si algo no cuadra.
 */
final class KioskServiceProvider extends ServiceProvider {}
