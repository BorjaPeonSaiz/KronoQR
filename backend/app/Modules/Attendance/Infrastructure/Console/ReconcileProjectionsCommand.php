<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Console;

use App\Modules\Attendance\Application\Command\ReconcileDailyTotalsCommand;
use App\Modules\Attendance\Application\Exception\InvalidReconciliationRange;
use App\Modules\Attendance\Application\UseCase\ReconcileDailyTotals;
use App\Modules\Attendance\Application\UseCase\ReconciliationReport;
use Illuminate\Console\Command;

/**
 * `php artisan attendance:reconcile --from= --to=` — la reconciliacion de
 * `daily_totals` con sus eventos origen (RF-PR-02, doc 02 Anexo C: «Recalcula
 * proyecciones y alerta si divergen»; ADR-007 lo nombra explicitamente).
 *
 * Lo ejecuta el planificador cada madrugada sobre la jornada de ayer
 * (`routes/console.php`). A mano sirve para revisar un rango mas ancho despues
 * de una importacion, de una restauracion o de una migracion que haya tocado la
 * proyeccion — que es exactamente lo que dice el §10.4 que hay que hacer:
 * `daily_totals` es reconstruible, y la recuperacion es este comando y no un
 * `UPDATE`.
 *
 * ## El codigo de salida no es cero si hubo divergencias
 *
 * Aunque las haya corregido todas. Es la diferencia con
 * `attendance:detect-incidents`, que termina en verde por muchos hallazgos que
 * encuentre: alli encontrar cosas **es** el trabajo del comando; aqui encontrar
 * una sola cosa significa que la proyeccion se desvio de sus eventos origen, y
 * eso no deberia poder ocurrir nunca (regla dura 7, doc 02 §8.2:
 * `projection_divergence_total` debe permanecer siempre en cero). Un codigo cero
 * dejaria el suceso solo en la metrica; con este queda ademas en el log del
 * planificador y lo ve cualquiera que encadene el comando en un script.
 *
 * **Lo que ese codigo de salida NO hace todavia:** despertar a nadie. No hay
 * `onFailure()` en la programacion, ni regla de Loki sobre la salida del
 * comando, ni una serie `projection_reconciliation_last_failures` que alertar
 * con `> 0`. Quien vigila hoy la divergencia es
 * `projection_divergence_total` mas la regla `absent()` sobre el sello de
 * tiempo; lo demas es de la tarea 3.2 y esta anotado alli.
 *
 * ## Lo que este comando NO hace
 *
 * **No toca ni un tramo.** No cierra turnos, no corrige horas y no anula nada:
 * lo unico que reescribe es la copia derivada. Si el total del dia estaba mal
 * porque el fichaje esta mal, esto lo deja igual de mal y lo dice — la
 * correccion del registro la firma una persona (RF-PA-04, RN-13).
 *
 * **No imprime ningun nombre** (regla dura 21): el resumen son recuentos por
 * columna y el detalle va al log con `employee_uuid`.
 */
final class ReconcileProjectionsCommand extends Command
{
    protected $signature = 'attendance:reconcile
        {--from= : Primera jornada del rango, en formato YYYY-MM-DD. Por defecto, ayer}
        {--to= : Ultima jornada del rango, en formato YYYY-MM-DD. Por defecto, la misma que --from}';

    protected $description = 'Contrasta daily_totals con los tramos vigentes, corrige lo que no cuadre y alerta si divergen (RF-PR-02)';

    public function handle(ReconcileDailyTotals $reconcile): int
    {
        $from = $this->isoDate('from');
        $to = $this->isoDate('to');

        if ($from === false || $to === false) {
            // Nada se corrige en silencio: un `--from=marzo` no cae al valor por
            // defecto, porque quien lo escribio creeria haber revisado marzo
            // entero cuando solo se habria revisado ayer.
            $this->error(
                '--from y --to esperan una fecha en formato YYYY-MM-DD. '
                .'Sin fechas validas no se adivina el rango: la pasada por defecto es la jornada de ayer.'
            );

            return self::INVALID;
        }

        try {
            $report = $reconcile->handle(new ReconcileDailyTotalsCommand($from, $to));
        } catch (InvalidReconciliationRange $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        if (! $report->ranOverASite) {
            $this->warn('Todavia no hay centro de trabajo: sin zona horaria no hay jornada que reconciliar (RF-PD-03).');

            // Cero y no error: antes de la puesta en marcha esto es lo esperado,
            // y un planificador que fallara cada noche llenaria el log de una
            // instalacion recien instalada.
            return self::SUCCESS;
        }

        return $this->report($report);
    }

    /**
     * Imprime el desenlace y decide el codigo de salida.
     */
    private function report(ReconciliationReport $report): int
    {
        $this->info(sprintf(
            'Contrastadas %d jornadas de %s a %s (%d dias).',
            $report->workDaysInspected,
            $report->fromIsoDate,
            $report->toIsoDate,
            $report->daysInspected,
        ));

        if ($report->isClean()) {
            $this->line('Sin divergencias: la proyeccion coincide con los tramos vigentes (RN-06).');

            return self::SUCCESS;
        }

        foreach ($report->byField as $field => $count) {
            // `row` no es una columna: es la fila que faltaba entera.
            $this->line('  '.($field === 'row' ? 'fila ausente' : $field).': '.$count);
        }

        $this->warn(sprintf(
            '%d divergencia(s), %d corregida(s). La proyeccion se habia desviado de sus eventos origen: '
            .'no es mantenimiento rutinario. Sigue docs/runbooks/divergencia-proyeccion.md.',
            $report->divergences,
            $report->corrected,
        ));

        if ($report->failures > 0) {
            $this->error(sprintf(
                '%d jornada(s) no se pudieron reconciliar. Busca «attendance.projection_not_corrected» '
                .'y «attendance.projection_reconciliation_failed» en el log para ver cuales.',
                $report->failures,
            ));
        }

        return self::FAILURE;
    }

    /**
     * La fecha de una opcion: `null` si no llego, `false` si llego y no es una
     * fecha ISO.
     *
     * Se comprueba la **forma** aqui y la validez del calendario en el dominio
     * (`WorkDate::fromIsoDate()`), que es quien sabe que el 30 de febrero no
     * existe.
     */
    private function isoDate(string $option): string|false|null
    {
        $value = $this->option($option);

        // Sin la opcion —o con ella vacia, que es como llega `--from=`— manda el
        // valor por defecto del caso de uso. Es el camino del planificador.
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? trim($value) : false;
    }
}
