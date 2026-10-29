<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Sonda `scheduler.scheduled_offboardings` de `product:doctor` (RF-PD-13, RN-14,
 * ADR-062 §4): **bajas programadas que siguen sin aplicar pasado su tope**.
 *
 * Es la misma pregunta que la serie `kronoqr_scheduled_offboardings_overdue` y
 * la alerta `ScheduledOffboardingOverdue`, pero contestada contra la base y no
 * contra lo que publico la ultima pasada: si el planificador lleva dias parado,
 * la serie se quedo en el valor de su ultima pasada y esta sonda no.
 *
 * «Hoy» es la fecha civil del centro, con el puerto `Clock` y `sites.timezone`,
 * como en la tarea (regla dura 3). Pendiente pasado el tope es la baja
 * programada —ficha que no esta de baja y tiene fecha de cese— cuyo cese es
 * anterior a ayer. El predicado es el mismo, columna a columna, que el del
 * repositorio de la plantilla y el del indice parcial `scheduled_offboardings`:
 * `status <> terminated`, `terminated_at` no nulo y anterior a la fecha. Lee
 * `employees` desde `Product` como las demas sondas y colectores; un puerto de
 * lectura de la plantilla es trabajo pendiente.
 *
 * **Aviso y nunca fallo**: nadie puede fichar despues del cese aunque la baja no
 * se haya aplicado, asi que no hay registro en riesgo; lo que queda a medias son
 * las tarjetas y el asiento. Sin centro no se pudo programar ninguna baja, y la
 * sonda dice `ok`. No lanza: lo que no puede comprobar lo dice como aviso.
 */
final readonly class ScheduledOffboardingsProbe implements DoctorProbe
{
    private const string ID = 'scheduler.scheduled_offboardings';

    public function __construct(
        private ConnectionInterface $database,
        private Clock $clock,
    ) {}

    public function family(): string
    {
        return 'scheduler';
    }

    public function run(): array
    {
        try {
            $timezone = $this->database->table('sites')->orderBy('id')->value('timezone');

            if (! \is_string($timezone) || $timezone === '') {
                return [DoctorFinding::ok(self::ID, ['overdue' => 0], ['count' => 0])];
            }

            $yesterday = $this->clock->now()->setTimezone(new DateTimeZone($timezone))->modify('-1 day')->format('Y-m-d');

            $overdue = $this->database->table('employees')
                ->where('status', '<>', EmploymentStatus::TERMINATED->value)
                ->whereNotNull('terminated_at')
                ->where('terminated_at', '<', $yesterday)
                ->count();
        } catch (Throwable) {
            return [DoctorFinding::warning(self::ID, 'unknown')];
        }

        $details = ['overdue' => $overdue];

        if ($overdue > 0) {
            return [DoctorFinding::warning(self::ID, params: ['count' => $overdue], details: $details)];
        }

        return [DoctorFinding::ok(self::ID, $details, ['count' => 0])];
    }
}
