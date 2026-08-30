<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Console;

use App\Modules\Attendance\Application\Command\DetectAnomaliesCommand;
use App\Modules\Attendance\Application\UseCase\DetectAttendanceAnomalies;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan attendance:detect-incidents` — la revision diaria del registro
 * horario (RF-PR-01, doc 02 Anexo C: «Turnos abiertos, duraciones anomalas,
 * descansos»).
 *
 * Lo ejecuta el planificador de madrugada (`routes/console.php`). A mano sirve
 * para revisar una ventana mas ancha despues de una importacion o de arreglar
 * una configuracion, que es la unica situacion en la que mirar hacia atras tiene
 * sentido.
 *
 * ## Retroactividad: por defecto, no
 *
 * La ventana es `COMPLIANCE_INCIDENT_LOOKBACK_DAYS` (7 dias de serie) y la
 * decision esta escrita en el doc 01 §4, junto a RN-08: **la deteccion no
 * reprocesa el historico**. Recalcular el pasado abriria incidencias sobre
 * jornadas ya entregadas a la plantilla o a la Inspeccion, y una incidencia
 * abierta hoy sobre una jornada de hace dos años no describe nada que nadie
 * pueda corregir. `--days` la amplia para una ejecucion concreta, y eso es una
 * decision consciente de quien la lanza.
 *
 * **Los tramos todavia abiertos se revisan siempre**, fuera de la ventana: un
 * turno sin cerrar no es historia, es un hecho que sigue creciendo. Es la
 * excepcion declarada, y es lo que hace que el olvido de salida de hace tres
 * meses siga apareciendo hasta que alguien lo corrija.
 *
 * ## Lo que este comando NO hace
 *
 * **No cierra ningun tramo** (RN-08, regla dura 19). No corrige horas, no
 * descarta escaneos y no penaliza a nadie: emite hallazgos que `Compliance`
 * convierte en incidencias asignadas a una persona. Su codigo de salida es `0`
 * aunque encuentre cien: encontrar cosas es su trabajo, no un fallo.
 *
 * **No imprime ningun nombre** (regla dura 21): el resumen son recuentos por
 * tipo. Quien necesita el detalle lo tiene en la bandeja, que se lee con
 * autorizacion.
 */
final class DetectIncidentsCommand extends Command
{
    protected $signature = 'attendance:detect-incidents
        {--days= : Dias hacia atras a revisar. Por defecto, compliance.incident_detection.lookback_days}';

    protected $description = 'Revisa el registro horario y abre las incidencias que requieren intervencion humana (RF-PR-01)';

    public function handle(DetectAttendanceAnomalies $detect): int
    {
        $days = $this->lookbackDays();

        $result = $detect->handle(new DetectAnomaliesCommand($days));

        if (! $result->ranOverASite) {
            $this->warn('Todavia no hay centro de trabajo: sin zona horaria no hay jornada que revisar (RF-PD-03).');

            // Cero y no error: antes de la puesta en marcha esto es lo esperado,
            // y un planificador que fallara cada noche llenaria el log de una
            // instalacion recien instalada.
            return self::SUCCESS;
        }

        // El log lleva recuentos y ventana, nunca personas (regla dura 21). Es lo
        // que permite ver desde fuera si la pasada corrio y que encontro.
        Log::info('attendance.incident_detection', [
            'days_inspected' => $result->daysInspected,
            'work_days_inspected' => $result->workDaysInspected,
            'anomalies' => $result->byType,
        ]);

        $this->info(sprintf(
            'Revisadas %d jornadas de los ultimos %d dias, mas los turnos abiertos.',
            $result->workDaysInspected,
            $result->daysInspected,
        ));

        if ($result->total() === 0) {
            $this->line('Sin hallazgos.');

            return self::SUCCESS;
        }

        foreach ($result->byType as $type => $count) {
            $this->line('  '.$type.': '.$count);
        }

        $this->line(
            'Los hallazgos ya abiertos no se duplican: repetir el comando es seguro. '
            .'Ningun tramo se ha cerrado (RN-08).'
        );

        return self::SUCCESS;
    }

    /**
     * La ventana efectiva: la de `--days` si se indico, y si no la configurada.
     *
     * Un `--days=0` o negativo no se corrige en silencio a un valor razonable:
     * el comando de dominio lo rechaza, porque adivinar que queria decir quien
     * escribio `--days=-3` es como se acaba revisando el historico entero por
     * accidente.
     */
    private function lookbackDays(): int
    {
        $option = $this->option('days');

        if (is_numeric($option)) {
            return (int) $option;
        }

        return Config::integer('compliance.incident_detection.lookback_days', 7);
    }
}
