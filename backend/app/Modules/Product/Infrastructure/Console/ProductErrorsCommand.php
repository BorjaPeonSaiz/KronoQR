<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Application\Port\ErrorEventQuery;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Application\UseCase\ListErrorEvents;
use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorEventPage;
use App\Modules\Product\Domain\ValueObject\ErrorEventStatusFilter;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * `php artisan product:errors` — que esta fallando, desde la consola (Anexo C
 * del doc 01, **RF-PD-15**, decision 10 de la ficha 5.12).
 *
 * ## Para quien esta escrito
 *
 * Para la misma persona que `product:doctor`: el IT del hotel, que no conoce
 * este sistema y que ya tiene un problema. De ahi que la salida empiece por los
 * **criticos**, que cada grupo diga cuantas veces ha pasado y desde cuando, y
 * que el `trace_id` este a la vista para poder buscarlo en el log tecnico.
 *
 * Existe ademas del panel porque **el panel puede ser justamente lo que no
 * funciona**. Un producto cuyo unico diagnostico esta detras de la aplicacion
 * que falla no sirve de nada el dia que hace falta.
 *
 * ## Codigos de salida, y por que son los de `doctor`
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | Ningun grupo abierto en el periodo. |
 * | `1` | Hay grupos de nivel `error`. Algo que mirar; nada esta parado. |
 * | `2` | Hay al menos un `critical`. Algo que nadie ve o que no puede parar. |
 *
 * Son deliberadamente los mismos que `product:doctor` para que **un script pueda
 * preguntar**: quien monitoriza esta instalacion ya sabe interpretar «0 bien, 1
 * mirar, 2 actuar» y no tiene que aprender una segunda tabla. Un `2` aqui no
 * significa lo mismo que un `2` de `doctor` —alli es una comprobacion fallida,
 * aqui un error critico registrado—, pero la accion que dispara es la misma.
 *
 * A diferencia de `doctor`, **este comando no lo ejecuta ningun script del
 * producto**: ni `install.sh` ni `update.sh` lo llaman. Sus codigos estan aqui
 * para el cliente, no para nosotros.
 *
 * ## Un solo idioma en todo el informe, y sale de la instalacion
 *
 * Mismo orden de resolucion que `doctor`: `--lang`, despues `LOCALE_DEFAULT` de
 * la instalacion —lo que el cliente eligio (ADR-017, RF-PD-01)— y solo entonces
 * `APP_LOCALE`. Ninguna frase del marco esta escrita a mano aqui: viven en
 * `lang/{es,en}/errors.php`.
 *
 * Se traduce con {@see DoctorTranslator} y no con un puerto nuevo. El nombre se
 * queda corto -es el traductor de las claves del diagnostico, no solo del
 * informe de `doctor`- pero lo que hace es exactamente lo que hace falta:
 * devolver `null` cuando la clave no existe y **recibir el idioma como
 * argumento** en lugar de mirar el estado global del proceso. Un segundo puerto
 * identico solo anadiria una interfaz que mantener.
 *
 * ## `--json` para que lo lea un programa
 *
 * Misma forma que la seccion `error_events` del paquete de diagnostico, para que
 * quien aprende a leer una sepa leer la otra.
 *
 * ## Funciona con la licencia caducada o ausente (regla dura 15)
 */
final class ProductErrorsCommand extends Command
{
    /** Lo que se pide sin decir nada: el ultimo dia. */
    private const string DEFAULT_SINCE = '24h';

    /** Techo de grupos en pantalla. Por encima, el panel es mejor herramienta. */
    private const int MAX_ROWS = 100;

    protected $signature = 'product:errors
        {--since=24h : Periodo hacia atras (30m, 24h, 7d, 2w). Por defecto, 24h}
        {--level= : Solo un nivel (error|critical)}
        {--source= : Solo un origen (api|worker|scheduler|console|kiosk|admin|portal)}
        {--json : Devuelve el informe como JSON, para un script}
        {--lang= : Idioma del informe (es|en). Por defecto, el de la instalacion}';

    protected $description = 'Lista los errores agrupados del periodo y sale 0, 1 o 2 segun su severidad';

    /** El idioma de TODO el informe, resuelto una sola vez. */
    private string $locale = 'es';

    public function handle(
        ListErrorEvents $errors,
        DoctorTranslator $translator,
        GetSettingsHandler $settings,
        Clock $clock,
        Config $config,
    ): int {
        $this->locale = $this->resolveLocale($settings, $config);

        $since = $this->since($clock->now());

        if ($since === null) {
            $this->output->writeln($this->say($translator, 'bad_since', [
                'value' => (string) $this->option('since'),
            ]));

            // `2` y no `1`: una peticion que no se entiende no puede confundirse
            // con «no hay errores», que es lo que un `0` le diria a un script.
            return 2;
        }

        $level = self::text($this->option('level'));
        $level = $level === null ? null : ErrorLevel::tryFrom($level);

        $source = self::text($this->option('source'));
        $source = $source === null ? null : ErrorSource::tryFrom($source);

        if (self::text($this->option('level')) !== null && ! $level instanceof ErrorLevel) {
            $this->output->writeln($this->say($translator, 'bad_level', [
                'values' => implode(', ', ErrorLevel::names()),
            ]));

            return 2;
        }

        if (self::text($this->option('source')) !== null && ! $source instanceof ErrorSource) {
            $this->output->writeln($this->say($translator, 'bad_source', [
                'values' => implode(', ', ErrorSource::names()),
            ]));

            return 2;
        }

        /*
         * Del caso de uso solo interesa la pagina: la zona del centro que
         * tambien devuelve es para el panel. En la consola **todo sale en UTC**,
         * que es como esta almacenado (regla dura 3) y como se compara con el
         * log tecnico, que tambien esta en UTC.
         */
        $query = new ErrorEventQuery(
            status: ErrorEventStatusFilter::Open,
            source: $source,
            level: $level,
            from: $since,
            page: 1,
            perPage: self::MAX_ROWS,
        );

        $page = $errors->handle($query)->page;

        if ($this->option('json') === true) {
            $this->output->writeln((string) json_encode(
                $this->document($page, $since),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return $this->exitCode($errors, $query);
        }

        $this->render($page, $translator, $since);

        return $this->exitCode($errors, $query);
    }

    /**
     * `0` si no hay nada, `1` si hay algo, `2` si hay algo critico.
     *
     * ## Se cuenta, no se mira la pagina
     *
     * La pantalla trae como mucho cien grupos. Con el codigo de salida deducido
     * de esas cien filas, una instalacion con ciento veinte grupos abiertos de
     * nivel `error` y **un critico en la posicion ciento uno** salia `1`, y el
     * script de monitorizacion del cliente no se enteraba de lo unico que tenia
     * que mirar. El recuento va contra la consulta completa.
     *
     * ## Con los MISMOS filtros que se pidieron
     *
     * Quien ejecuta `--source=worker --since=1h` esta preguntando por eso y no
     * por otra cosa; los recuentos globales de `meta` describen toda la
     * instalacion y harian inutil el comando dentro de un script.
     *
     * Cuando el filtro ya fija el nivel no hace falta la segunda consulta: con
     * `--level=critical` el total ya son criticos, y con `--level=error` no
     * puede haber ninguno.
     */
    private function exitCode(ListErrorEvents $errors, ErrorEventQuery $query): int
    {
        $total = $this->countMatching($errors, $query);

        if ($total === 0) {
            return 0;
        }

        return $this->criticalCount($errors, $query) > 0 ? 2 : 1;
    }

    private function criticalCount(ListErrorEvents $errors, ErrorEventQuery $query): int
    {
        if ($query->level === ErrorLevel::Critical) {
            return $this->countMatching($errors, $query);
        }

        if ($query->level === ErrorLevel::Error) {
            return 0;
        }

        return $this->countMatching($errors, new ErrorEventQuery(
            status: $query->status,
            source: $query->source,
            level: ErrorLevel::Critical,
            from: $query->from,
            to: $query->to,
            page: 1,
            perPage: 1,
        ));
    }

    /**
     * El total de la consulta, sin traer las filas: `perPage: 1` y se lee el
     * `total`, que es el de la consulta completa.
     */
    private function countMatching(ListErrorEvents $errors, ErrorEventQuery $query): int
    {
        return $errors->handle(new ErrorEventQuery(
            status: $query->status,
            source: $query->source,
            level: $query->level,
            from: $query->from,
            to: $query->to,
            page: 1,
            perPage: 1,
        ))->page->total;
    }

    /**
     * Una opcion de la linea de comandos como texto, o nula si no se paso.
     *
     * `Command::option()` devuelve `mixed` —una bandera es `bool` y una opcion
     * repetible es un array—, asi que la conversion esta en un sitio y no
     * repartida por tres condiciones.
     */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * El instante desde el que se mira, o `null` si `--since` no se entiende.
     *
     * **No se acepta una cifra a secas.** `--since=24` es ambiguo —¿horas,
     * dias?— y adivinarlo daria un informe que dice otra cosa de la que quien lo
     * pidio cree estar leyendo.
     */
    private function since(DateTimeImmutable $now): ?DateTimeImmutable
    {
        $raw = $this->option('since');
        $value = is_string($raw) && $raw !== '' ? strtolower(trim($raw)) : self::DEFAULT_SINCE;

        if (preg_match('/^(\d+)(m|h|d|w)$/', $value, $matches) !== 1) {
            return null;
        }

        $amount = max(1, (int) $matches[1]);

        $interval = match ($matches[2]) {
            'm' => 'PT'.$amount.'M',
            'h' => 'PT'.$amount.'H',
            'd' => 'P'.$amount.'D',
            default => 'P'.($amount * 7).'D',
        };

        return $now->sub(new DateInterval($interval));
    }

    /**
     * El idioma del informe: `--lang`, el de la instalacion, o `APP_LOCALE`.
     * Mismo orden y mismo motivo que `product:doctor`.
     */
    private function resolveLocale(GetSettingsHandler $settings, Config $config): string
    {
        $requested = $this->option('lang');

        if (is_string($requested) && $requested !== '') {
            return $requested;
        }

        try {
            $configured = $settings->handle()->text(SettingKey::LOCALE_DEFAULT);

            if ($configured !== '') {
                return $configured;
            }
        } catch (Throwable) {
            // La base de datos puede estar a medias: es la mitad de los casos en
            // los que alguien ejecuta esto. Se sigue con el idioma del `.env`.
        }

        $fallback = $config->get('app.locale');

        return is_string($fallback) && $fallback !== '' ? $fallback : 'es';
    }

    /**
     * @return array<string, mixed>
     */
    private function document(ErrorEventPage $page, DateTimeImmutable $since): array
    {
        return [
            'since' => self::utc($since),
            'open_errors' => $page->openErrors,
            'open_critical' => $page->openCritical,
            'total' => $page->total,
            'groups' => array_map(static fn (ErrorEvent $row): array => [
                'id' => $row->id,
                'level' => $row->level->value,
                'source' => $row->source->value,
                'module' => $row->module,
                'code' => $row->code,
                'message' => $row->message,
                'occurrences' => $row->occurrences,
                'first_seen_at' => self::utc($row->firstSeenAt),
                'last_seen_at' => self::utc($row->lastSeenAt),
                'app_version' => $row->appVersion,
                'trace_id' => $row->traceId,
            ], $page->rows),
        ];
    }

    private function render(ErrorEventPage $page, DoctorTranslator $translator, DateTimeImmutable $since): void
    {
        $this->line('');
        $this->line($this->say($translator, 'title', ['since' => self::utc($since)]));
        $this->line('==============================');
        $this->line('');

        if ($page->rows === []) {
            $this->line($this->say($translator, 'none'));
            $this->line('');

            return;
        }

        $this->line($this->say($translator, 'found', [
            'shown' => \count($page->rows),
            'total' => $page->total,
        ]));
        $this->line('');

        // Los criticos primero. Hacer recorrer treinta lineas de avisos hasta el
        // que impide fichar es una forma de esconderlo (mismo criterio que
        // `product:doctor`).
        foreach ([ErrorLevel::Critical, ErrorLevel::Error] as $level) {
            foreach ($page->rows as $row) {
                if ($row->level === $level) {
                    $this->group($row, $translator);
                }
            }
        }

        $this->line($this->say($translator, 'open_totals', [
            'errors' => $page->openErrors,
            'critical' => $page->openCritical,
        ]));
        $this->line($this->say($translator, 'what_to_do'));
        $this->line('');
    }

    private function group(ErrorEvent $row, DoctorTranslator $translator): void
    {
        $tag = $row->level === ErrorLevel::Critical
            ? '['.$this->say($translator, 'tag_critical').']'
            : '['.$this->say($translator, 'tag_error').']   ';

        $this->line('  '.$tag.' #'.$row->id.' '.$row->source->value.($row->code === null ? '' : ' · '.$row->code));
        $this->line('      '.$row->message);
        $this->line('      '.$this->say($translator, 'seen', [
            'count' => $row->occurrences,
            'first' => self::utc($row->firstSeenAt),
            'last' => self::utc($row->lastSeenAt),
        ]));

        if ($row->traceId !== null) {
            $this->line('      '.$this->say($translator, 'trace', ['trace_id' => $row->traceId]));
        }

        $this->line('');
    }

    /**
     * Un texto del marco del informe, en el idioma ya resuelto.
     *
     * Devuelve la clave si falta la traduccion, por el mismo criterio que
     * `doctor`: una clave a la vista delata el descuido en la primera ejecucion;
     * una linea en blanco lo esconde.
     *
     * @param  array<string, string|int|float|bool|null>  $params
     */
    private function say(DoctorTranslator $translator, string $key, array $params = []): string
    {
        return $translator->translate('errors.report.'.$key, $params, $this->locale) ?? 'errors.report.'.$key;
    }

    /** UTC siempre (regla dura 3); el panel es quien pinta la hora del centro. */
    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
