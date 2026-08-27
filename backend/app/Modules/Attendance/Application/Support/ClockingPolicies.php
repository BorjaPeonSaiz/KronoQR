<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Support;

use App\Modules\Attendance\Domain\Policy\ClockingPolicy;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use App\Modules\Shared\Domain\ValueObject\OperationalSettings;

/**
 * Construye la politica de RN-07 y RN-08 con los umbrales del centro ya
 * resueltos (regla dura 14).
 *
 * **Existe para que el umbral no se escriba dos veces, y es el UNICO sitio.**
 * `RegisterScanHandler` llego a tener una copia propia de la constante (deuda
 * de la 1.4 que la auditoria de cierre destapo como duplicacion viva); hoy
 * consume este metodo, igual que las correcciones de la 1.15 —un tramo
 * corregido a catorce horas pide revision humana como uno fichado—. Una
 * segunda copia seria la forma segura de que dentro de seis meses un fichaje y
 * una correccion clasifiquen distinto la misma duracion.
 *
 * **Deuda heredada de la tarea 1.4, no nueva.** RN-07 no tiene todavia clave en
 * `installation_settings`: el Anexo B siembra cuatro umbrales y ninguno es este.
 * Un minuto es el perfil de serie que documenta `ClockingPolicy` y coincide con
 * el suelo real del calculo, porque `WorkedDuration` trunca los segundos.
 * Cuando la tarea 5.1 abra la configuracion desde el panel, este valor sube a
 * `installation_settings` con los otros cuatro y esta constante desaparece.
 */
final readonly class ClockingPolicies
{
    /** RN-07, duracion minima computable. Ver la deuda declarada arriba. */
    private const int MINIMUM_COMPUTABLE_MINUTES = 1;

    public static function forSettings(OperationalSettings $settings): ClockingPolicy
    {
        return new ClockingPolicy(
            WorkedDuration::ofMinutes(self::MINIMUM_COMPUTABLE_MINUTES),
            WorkedDuration::ofMinutes($settings->anomalousShiftMinutes),
        );
    }
}
