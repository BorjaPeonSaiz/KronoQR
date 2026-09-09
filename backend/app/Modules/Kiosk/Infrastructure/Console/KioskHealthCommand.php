<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Infrastructure\Console;

use App\Modules\Kiosk\Application\UseCase\CheckKioskHealth;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthReport;
use App\Modules\Kiosk\Domain\ValueObject\KioskHealthRow;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Translation\Translator;
use Throwable;

/**
 * `php artisan kiosk:health [--json] [--lang=]` — **el estado de todos los
 * quioscos desde la consola** (RF-PA-07, doc 02 Anexo C).
 *
 * ## Por que existe
 *
 * Porque tres documentos entregados al cliente ya lo mandan ejecutar: el runbook
 * `alta-nuevo-quiosco.md` lo usa para verificar un alta (§4.2), para comprobar
 * que la cola esta a cero antes de desvincular una tablet averiada (§5.1) —los
 * fichajes sin enviar se pierden al revocar el token, y son registro horario de
 * personas reales— y para diagnosticar un emparejamiento sin red (§6); y la fila
 * 20 de la lista de comprobacion de `docs/cliente/endurecimiento.md` lo ejecuta
 * cada trimestre. Un comando citado en una lista de comprobacion y que no existe
 * enseña a ignorar la lista entera.
 *
 * ## Solo lectura, y no toca la API
 *
 * Muestra lo mismo que la pantalla «Quioscos» del panel —la misma consulta, ver
 * {@see CheckKioskHealth}— para cuando el panel no esta accesible, que es cuando
 * de verdad hace falta. No hay endpoint nuevo y el contrato no cambia.
 *
 * ## Codigos de salida, los mismos que `product:doctor`
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | Todos los quioscos activos al dia y sin cola. |
 * | `1` | **Avisos.** Latido atrasado, cola sin drenar, o ningun quiosco activo. |
 * | `2` | **Fallos.** Algun quiosco activo lleva mas del plazo de silencio sin aparecer. |
 *
 * El detalle de cada frontera y el porque de cada plazo estan en
 * {@see KioskHealthRow::of()} y en {@see KioskHealthReport}.
 *
 * ## Un solo idioma en todo el informe, y sale de la instalacion
 *
 * Ni una linea esta escrita a mano aqui: encabezados, veredictos, consejos y el
 * resultado viven en `lang/{es,en}/kiosk.php`. El orden de resolucion es el
 * mismo que el de `product:doctor` —`--lang`, despues el idioma que el cliente
 * eligio en el panel (`LOCALE_DEFAULT`, ADR-017) y solo entonces `APP_LOCALE`—
 * porque el informe lo lee la misma persona.
 *
 * ## La hora sale en la zona del centro, y esa conversion ocurre AQUI
 *
 * Regla dura 3: todo se almacena y se calcula en UTC, y la zona del centro se
 * aplica en presentacion. Una consola es presentacion. El `--json`, en cambio,
 * sale siempre en UTC con sufijo `Z`: quien lo consume es un script.
 *
 * ## Ni un dato personal (regla dura 21)
 *
 * Un quiosco no tiene titular. **No se imprime el token ni su hash** —no salen
 * siquiera de la consulta— ni la clave interna de la fila.
 */
final class KioskHealthCommand extends Command
{
    protected $signature = 'kiosk:health
        {--json : Devuelve el estado como JSON, para scripts y para el paquete de diagnostico}
        {--lang= : Idioma del informe (es|en). Por defecto, el de la instalacion}';

    protected $description = 'Muestra el estado de todos los quioscos: ultimo contacto, cola pendiente y que hay que mirar.';

    /** El idioma de TODO el informe, resuelto una sola vez. */
    private string $locale = 'es';

    public function handle(
        CheckKioskHealth $health,
        Translator $translator,
        LocalePolicyProvider $locales,
        InstallationSiteProvider $sites,
        Config $config,
    ): int {
        $this->locale = $this->resolveLocale($locales, $config);

        $report = $health->handle();

        if ($this->option('json') === true) {
            $this->output->writeln((string) json_encode(
                $report->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return $report->exitCode();
        }

        $this->render($report, $translator, $this->resolveTimezone($sites));

        return $report->exitCode();
    }

    /**
     * El idioma del informe: `--lang`, el de la instalacion, o `APP_LOCALE`.
     *
     * `LOCALE_DEFAULT` antes que `APP_LOCALE` porque es el que el cliente eligio
     * desde el panel y el que ven sus tres aplicaciones (ADR-017); `APP_LOCALE`
     * es lo que quedo en el `.env` el dia de la instalacion.
     *
     * Con la base de datos caida se cae al `.env` en lugar de dejar a nadie sin
     * informe: este comando tambien se ejecuta cuando algo esta roto.
     */
    private function resolveLocale(LocalePolicyProvider $locales, Config $config): string
    {
        $requested = $this->option('lang');

        if (is_string($requested) && $requested !== '') {
            return $requested;
        }

        try {
            return $locales->current()->default;
        } catch (Throwable) {
            $fallback = $config->get('app.locale');

            return is_string($fallback) && $fallback !== '' ? $fallback : 'es';
        }
    }

    /**
     * La zona del centro, para las horas absolutas de la tabla (regla dura 3).
     *
     * `UTC` si la instalacion todavia no tiene centro —antes del asistente de
     * puesta en marcha— o si no se puede consultar. Un quiosco que no responde se
     * diagnostica igual con la hora en UTC; quedarse sin informe por no saber la
     * zona, no.
     */
    private function resolveTimezone(InstallationSiteProvider $sites): DateTimeZone
    {
        try {
            $timezone = $sites->installationSite()?->timezone;

            return new DateTimeZone($timezone ?? 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    private function render(KioskHealthReport $report, Translator $translator, DateTimeZone $zone): void
    {
        $this->line('');
        $this->line($this->say($translator, 'title', [
            'moment' => $this->absolute($report->generatedAt, $zone, $translator),
            'zone' => $zone->getName(),
        ]));
        $this->line('==============================');
        $this->line('');

        if ($report->devices === []) {
            // Sin ninguna fila no hay tabla que pintar, y el mensaje tiene que
            // decir que hacer: quien ejecuta esto acaba de instalar.
            $this->line($this->say($translator, 'fleet_empty'));
            $this->result($report, $translator);

            return;
        }

        $this->table(
            [
                $this->say($translator, 'column.name'),
                $this->say($translator, 'column.status'),
                $this->say($translator, 'column.version'),
                $this->say($translator, 'column.last_seen'),
                $this->say($translator, 'column.queue'),
                $this->say($translator, 'column.verdict'),
            ],
            array_map(fn (KioskHealthRow $row): array => [
                $row->name,
                $this->say($translator, 'status.'.$row->status),
                $row->appVersion ?? '-',
                $this->lastSeen($row, $zone, $translator),
                (string) $row->pendingQueueSize,
                $this->say($translator, 'verdict.'.$row->verdict->value),
            ], $report->devices),
        );

        if ($report->active() === 0) {
            $this->line('');
            $this->line($this->say($translator, 'fleet_all_revoked'));
        }

        $this->advice($report, $translator);
        $this->result($report, $translator);
    }

    /**
     * El «que hay que mirar» de cada fila con hallazgos, y los fallos primero.
     *
     * La tabla dice el veredicto; esto dice la accion, que no es la misma para
     * las dos causas de un aviso: una cola pendiente se resuelve **esperando** a
     * que la tablet drene —runbook §5.1, antes de desvincular— y un latido
     * atrasado se resuelve **mirando la red**.
     */
    private function advice(KioskHealthReport $report, Translator $translator): void
    {
        $problems = $report->problems();

        if ($problems === []) {
            return;
        }

        $this->line('');
        $this->line($this->say($translator, 'advice_header'));
        $this->line('------------------------');

        foreach ($problems as $row) {
            $this->line('  '.$row->name.' — '.$this->say($translator, 'advice.'.$row->reason->value, [
                'elapsed' => $this->duration($row->secondsSinceLastSeen ?? 0, $translator),
                'queue' => $row->pendingQueueSize,
            ]));
        }
    }

    private function result(KioskHealthReport $report, Translator $translator): void
    {
        $this->line('');
        $this->line($this->say($translator, 'result', [
            'label' => $this->say($translator, 'status_'.$report->status->value),
            'code' => $report->exitCode(),
        ]));
        $this->line($this->say($translator, 'meaning_'.$report->status->value));
        $this->line('');
    }

    /**
     * «Ultimo contacto», en relativo y en absoluto.
     *
     * **Los dos y no uno.** El relativo es lo que se compara con el umbral de un
     * vistazo —«hace 40 s» frente a «hace 3 h»—; el absoluto es lo que se cruza
     * con el resto de las evidencias de la incidencia: el corte de luz, la
     * ventana de mantenimiento, el turno en el que dejaron de llegar fichajes.
     */
    private function lastSeen(KioskHealthRow $row, DateTimeZone $zone, Translator $translator): string
    {
        if ($row->lastSeenAt === null || $row->secondsSinceLastSeen === null) {
            return $this->say($translator, 'relative.never');
        }

        return $this->say($translator, 'relative.ago', [
            'duration' => $this->duration($row->secondsSinceLastSeen, $translator),
        ]).' ('.$this->absolute($row->lastSeenAt, $zone, $translator).')';
    }

    /**
     * «40 s», «12 min», «3 h 5 min», «2 d».
     *
     * En abreviaturas y sin plurales a proposito: caben en una celda de tabla y
     * no obligan a que cada idioma resuelva su propia concordancia para decir un
     * numero que se lee de un vistazo.
     *
     * **La duracion y el «hace» son dos textos**, y no uno: la misma cifra se
     * dice «hace 3 h 4 min» en la columna y «lleva 3 h 4 min sin dar señales» en
     * el consejo, y en ingles el «ago» va detras. Con una sola clave, uno de los
     * dos idiomas acabaria con el orden cambiado.
     */
    private function duration(int $seconds, Translator $translator): string
    {
        if ($seconds < 60) {
            return $this->say($translator, 'duration.seconds', ['count' => $seconds]);
        }

        if ($seconds < 3600) {
            return $this->say($translator, 'duration.minutes', ['count' => intdiv($seconds, 60)]);
        }

        if ($seconds < 86400) {
            return $this->say($translator, 'duration.hours', [
                'hours' => intdiv($seconds, 3600),
                'minutes' => intdiv($seconds % 3600, 60),
            ]);
        }

        return $this->say($translator, 'duration.days', ['count' => intdiv($seconds, 86400)]);
    }

    /**
     * Un instante en la zona del centro (regla dura 3: la conversion es de la
     * presentacion, y una consola lo es).
     *
     * El formato es una clave de traduccion porque `09/09/2026` y `2026-09-09`
     * son el mismo dia para maquinas distintas y dias distintos para personas
     * distintas.
     */
    private function absolute(DateTimeImmutable $instant, DateTimeZone $zone, Translator $translator): string
    {
        return $instant->setTimezone($zone)->format($this->say($translator, 'absolute_format'));
    }

    /**
     * Un texto del informe, en el idioma ya resuelto.
     *
     * Devuelve la clave si falta la traduccion —que es lo que hace el traductor
     * de Laravel— por el mismo criterio que `product:doctor`: una clave a la
     * vista es fea y delata el descuido en la primera ejecucion; una linea en
     * blanco lo esconde.
     *
     * @param  array<string, string|int>  $params
     */
    private function say(Translator $translator, string $key, array $params = []): string
    {
        $line = $translator->get('kiosk.health.'.$key, $params, $this->locale);

        return is_string($line) ? $line : 'kiosk.health.'.$key;
    }
}
