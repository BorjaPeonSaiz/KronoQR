<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Notification;

use App\Modules\Reporting\Application\Port\WeeklySummaryRecipient;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Reporting\Domain\ValueObject\ReportedDuration;
use App\Modules\Reporting\Domain\ValueObject\WeeklySummary;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

/**
 * El resumen semanal que recibe un responsable de departamento (**RF-PR-05**,
 * doc 05 §5.7: «correo opcional al responsable de cada departamento»).
 *
 * ## Sincrona, y esto no es un descuido
 *
 * No lleva `ShouldQueue`, por lo mismo que sus dos hermanas —el aviso de
 * incidencias y el de informes en diferido—: encolada, `notify()` solo mete un
 * trabajo en Redis, el adaptador veria un exito **siempre** y la fila de
 * `weekly_summary_deliveries` quedaria escrita sobre un correo que nadie
 * recibio. Aqui eso es peor que en las otras dos, porque esa fila es justo lo
 * que impide reintentarlo la semana siguiente.
 *
 * El coste esta acotado: un comando programado de madrugada, fuera de cualquier
 * peticion, y un correo por responsable de departamento.
 *
 * ## Por que aqui si van nombres
 *
 * La regla dura 21 prohibe nombres de empleado en **logs tecnicos** y en
 * `error_events`, que viajan al fabricante en el paquete de diagnostico. Esto es
 * otra cosa: un resumen dirigido al responsable de esas personas, que ya ve sus
 * jornadas en el panel. Un correo que dijera «el empleado 018f…c3 trabajo 38:00»
 * obligaria a buscar un UUID a mano para saber de quien se habla, y un correo
 * que cuesta trabajo leer es un correo que se ignora. Que esos nombres salieron
 * consta en `audit_log` con el conjunto `weekly_summary` (RS-05).
 *
 * ## Lo que este correo NO hace
 *
 * - **No ordena por desviacion ni señala a nadie.** Las lineas van en el orden
 *   del informe. Un correo semanal que pusiera arriba a quien mas se desvia
 *   seria un cuadro de rendimiento, y este producto registra jornada: no valora
 *   el trabajo de nadie (doc 01 §12).
 * - **No detalla incidencias.** Solo cuantas hay abiertas. El detalle ya va en
 *   el aviso de RF-PR-01 y repetirlo serian dos correos con los mismos nombres.
 * - **No corrige nada ni pide que se corrija nada.** Es informacion.
 *
 * ## Y por que no lleva enlace absoluto
 *
 * El panel de cada cliente vive en un dominio distinto (ADR-016, ADR-017). El
 * correo dice **que** hay que abrir —la pantalla de informes y las dos fechas de
 * la semana— y no una URL que en la instalacion de al lado seria falsa. Es el
 * mismo criterio del aviso de incidencias.
 */
final class WeeklySummaryNotification extends Notification
{
    public function __construct(
        private readonly WeeklySummaryRecipient $recipient,
        private readonly WeeklySummary $summary,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->recipient->locale;
        $week = $this->summary->week;

        $message = (new MailMessage)
            ->subject(Lang::get('reports.weekly_summary.subject', [
                'from' => $week->isoStart(),
                'to' => $week->isoEnd(),
            ], $locale))
            ->greeting(Lang::get('reports.weekly_summary.greeting', [], $locale))
            ->line(Lang::get('reports.weekly_summary.intro', [
                'from' => $week->isoStart(),
                'to' => $week->isoEnd(),
                'week' => $week->label(),
                'departments' => $this->departments($locale),
            ], $locale));

        if ($this->summary->isEmpty()) {
            // Una semana sin nadie en el alcance no es un error: puede ser un
            // departamento recien creado. Se dice, en vez de mandar una tabla
            // vacia que parece una averia.
            return $this->closing($message, $locale);
        }

        $message->line(Lang::get('reports.weekly_summary.people', [
            'count' => $this->summary->employeeCount(),
        ], $locale));

        foreach ($this->summary->detailedRows() as $row) {
            $message->line($this->lineFor($row, $locale));
        }

        $undetailed = $this->summary->undetailedRows();

        if ($undetailed > 0) {
            $message->line(Lang::get('reports.weekly_summary.more', ['count' => $undetailed], $locale));
        }

        // Los totales, SOBRE TODAS las filas y no sobre las detalladas: un total
        // que describiera solo el trozo visible seria una cifra falsa con
        // aspecto de cifra buena.
        $message->line(Lang::get('reports.weekly_summary.totals', [
            'worked' => $this->clock($this->summary->workedMinutes()),
            'contracted' => $this->clock($this->summary->contractedMinutes()),
            'deviation' => $this->clock($this->summary->deviationMinutes()),
        ], $locale));

        return $this->closing($message, $locale);
    }

    /**
     * Las incidencias abiertas, el aviso de lo que el resumen no es, y el pie.
     *
     * Van juntas en un metodo porque son el cierre de los dos caminos —con
     * personas y sin ellas—: el recuento de la bandeja tiene que llegar tambien
     * la semana en la que nadie de tu departamento ficho.
     */
    private function closing(MailMessage $message, string $locale): MailMessage
    {
        $open = $this->summary->openIncidents;

        $message->line($open === 0
            ? Lang::get('reports.weekly_summary.incidents_none', [], $locale)
            : Lang::get('reports.weekly_summary.incidents', ['count' => $open], $locale));

        return $message
            ->line(Lang::get('reports.weekly_summary.action', [
                'from' => $this->summary->week->isoStart(),
                'to' => $this->summary->week->isoEnd(),
            ], $locale))
            ->line(Lang::get('reports.weekly_summary.not_a_ranking', [], $locale))
            ->line(Lang::get('reports.weekly_summary.footer', [], $locale));
    }

    private function lineFor(PeriodReportRow $row, string $locale): string
    {
        return Lang::get('reports.weekly_summary.line', [
            'employee' => $row->subject->fullName ?? '',
            'worked' => $this->clock($row->workedMinutes),
            'contracted' => $this->clock($row->contractedMinutes),
            'deviation' => $this->clock($row->deviationMinutes()),
            'days' => $row->daysWithActivity,
            'absences' => $row->absenceDays,
            'holidays' => $row->holidayDays,
        ], $locale);
    }

    /**
     * Los departamentos que aparecen en el resumen, sin repetir.
     *
     * Salen de las propias filas y no del alcance de la cuenta: el alcance son
     * identificadores y aqui hace falta el nombre, que ya viene con cada fila.
     * Un departamento del alcance sin nadie esta semana no se nombra, que es lo
     * honesto: el correo describe lo que lleva dentro.
     */
    private function departments(string $locale): string
    {
        $names = [];

        foreach ($this->summary->report->rows as $row) {
            $label = $row->subject->label;

            if ($label !== null && $label !== '') {
                $names[$label] = true;
            }
        }

        return $names === []
            ? Lang::get('reports.weekly_summary.no_department', [], $locale)
            : implode(', ', array_keys($names));
    }

    /** `HH:MM`, nunca decimal (`/informe-nuevo`, paso 6): ver {@see ReportedDuration}. */
    private function clock(int $minutes): string
    {
        return ReportedDuration::ofMinutes($minutes)->toClockText();
    }
}
