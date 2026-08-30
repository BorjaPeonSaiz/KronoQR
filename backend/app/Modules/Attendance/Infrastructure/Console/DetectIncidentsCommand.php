<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Console;

use App\Modules\Attendance\Application\Command\DetectAnomaliesCommand;
use App\Modules\Attendance\Application\UseCase\AnomalyScanResult;
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

        if ($days === null) {
            $this->error(
                '--days espera un numero entero de dias y ha llegado «'.(string) $this->option('days').'». '
                .'Sin valor valido no se adivina la ventana: revisar de mas abre incidencias sobre '
                .'jornadas ya entregadas, y revisar de menos las deja sin abrir.'
            );

            return self::INVALID;
        }

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
            'failures' => $result->failures,
        ]);

        $this->info(sprintf(
            'Revisadas %d jornadas de los ultimos %d dias, mas los turnos abiertos.',
            $result->workDaysInspected,
            $result->daysInspected,
        ));

        if ($result->total() === 0) {
            $this->line('Sin hallazgos.');

            return $this->report($result);
        }

        foreach ($result->byType as $type => $count) {
            $this->line('  '.$type.': '.$count);
        }

        $this->line(
            'Los hallazgos ya abiertos no se duplican: repetir el comando es seguro. '
            .'Ningun tramo se ha cerrado (RN-08).'
        );

        return $this->report($result);
    }

    /**
     * El desenlace de la pasada.
     *
     * **Distinto de cero si algun hallazgo no se pudo abrir**, aunque el resto si
     * se abriera: encontrar cosas es el trabajo de este comando y no un fallo,
     * pero no poder escribirlas si lo es, y una revision que termina en verde
     * habiendo perdido incidencias es peor que una que falla. El detalle de cual
     * fallo esta en el log, con `employee_uuid` y sin nombres (regla dura 21).
     */
    private function report(AnomalyScanResult $result): int
    {
        if ($result->failures === 0) {
            return self::SUCCESS;
        }

        $this->error(sprintf(
            '%d hallazgo(s) no se pudieron abrir como incidencia. El resto si. '
            .'Busca «attendance.incident_not_opened» en el log para ver cuales.',
            $result->failures,
        ));

        return self::FAILURE;
    }

    /**
     * La ventana efectiva: la de `--days` si se indico, y si no la configurada.
     * `null` cuando la opcion llego y no es un numero.
     *
     * Nada de esto se corrige en silencio. Un `--days=0` o negativo lo rechaza el
     * comando de dominio y un `--days=siete` lo rechaza aqui: caer al valor
     * configurado sin decir nada haria que quien escribio mal la opcion creyera
     * que reviso tres meses cuando reviso siete dias.
     */
    private function lookbackDays(): ?int
    {
        $option = $this->option('days');

        // Sin la opcion —o con ella vacia, que es como llega `--days=`— manda la
        // configuracion. Es el camino del planificador.
        if (! is_string($option) || trim($option) === '') {
            return Config::integer('compliance.incident_detection.lookback_days', 7);
        }

        // `is_numeric` aceptaria «7.5» y « 7»: se exige un entero escrito como
        // tal, porque media jornada de ventana no significa nada.
        return preg_match('/^-?\d+$/', $option) === 1 ? (int) $option : null;
    }
}
