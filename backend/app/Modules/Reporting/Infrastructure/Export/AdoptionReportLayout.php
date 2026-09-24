<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicator;
use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicatorUnit;
use App\Modules\Reporting\Domain\ValueObject\AdoptionOriginShare;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Reporting\Domain\ValueObject\AdoptionTarget;
use App\Modules\Reporting\Domain\ValueObject\AdoptionTargetComparison;
use App\Modules\Reporting\Domain\ValueObject\ReportedDuration;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * **Que columnas lleva el cuadro exportado y que dice cada celda** (**RF-IN-08**).
 *
 * ## Escrito una vez para los tres formatos
 *
 * CSV, XLSX y PDF recorren esta misma lista, igual que
 * {@see PeriodReportLayout} y por el mismo motivo: con las columnas declaradas en
 * cada escritor, una columna nueva acaba rotulada en un fichero y ausente en el
 * otro, y quien compare los dos creera que faltan datos.
 *
 * ## Las horas son `HH:MM` y los porcentajes llevan su signo
 *
 * `81:00` y no `81` ni `4860`. La respuesta JSON si lleva minutos —la consume un
 * programa—, pero un fichero lo abre una persona, y una columna de minutos al lado
 * de una de `HH:MM` es una invitacion a dividir entre 60 y volver al decimal que
 * `/informe-nuevo` prohibe. Los totales de mas de 24 h salen `168:00` y las
 * variaciones con signo `-12:30`, las dos de {@see ReportedDuration}.
 *
 * La **variacion** de un porcentaje se escribe `+2,30 pp` y no `+2,30 %`: son
 * puntos porcentuales, y llamarlos por ciento es como alguien acaba entendiendo
 * que el indicador ha mejorado un 2,3 % en vez de dos puntos y tres decimas.
 *
 * ## El objetivo y el estado van en el fichero, no solo en la pantalla
 *
 * Porque un cuadro sin su objetivo al lado es una tabla de porcentajes que nadie
 * puede juzgar, y porque el fichero es lo que se lleva a una reunion. El estado sale
 * de {@see AdoptionIndicator::meetsTarget()}, en el dominio, para que el papel y la
 * pantalla no puedan decir cosas distintas — y **«sin dato» no es «no cumple»**.
 *
 * ## Los rotulos salen de `lang/{es,en}/reports.php`, bloque `adoption`
 *
 * El idioma del documento es configuracion de la instalacion (regla dura 13,
 * ADR-017). Si falta una traduccion se rompe en voz alta: una clave suelta en la
 * cabecera de un cuadro de impacto es peor que un error, porque nadie la lee como
 * un fallo.
 */
final readonly class AdoptionReportLayout
{
    /**
     * Las columnas de la tabla de indicadores, en orden. La clave es la de
     * `reports.adoption.columns` de `lang/`.
     *
     * @var list<string>
     */
    public const array COLUMNS = ['indicator', 'current', 'previous', 'delta', 'target', 'status'];

    /**
     * Las columnas del reparto por origen, que es la **segunda** tabla del
     * documento.
     *
     * Va aparte y no como tres filas mas de la tabla de indicadores porque sus
     * columnas no significan lo mismo: «cuota» no tiene objetivo ni variacion, y
     * meterla arriba dejaria cuatro celdas vacias por fila invitando a leerlas como
     * datos que faltan.
     *
     * @var list<string>
     */
    public const array ORIGIN_COLUMNS = ['origin', 'scans', 'share'];

    /**
     * Ancho de cada columna del XLSX, en caracteres, en el orden de
     * {@see self::COLUMNS}.
     *
     * **No es cosmetica**: sin anchos, «Tiempo medio hasta resolver un turno sin
     * cerrar» sale cortado y una hoja de calculo abre las columnas numericas con
     * `#####`. La primera va muy ancha porque ahi estan los rotulos largos.
     *
     * @var list<float>
     */
    public const array COLUMN_WIDTHS = [48.0, 14.0, 18.0, 14.0, 30.0, 20.0];

    /** Anchos del reparto por origen. @var list<float> */
    public const array ORIGIN_COLUMN_WIDTHS = [26.0, 12.0, 10.0];

    private function __construct() {}

    /**
     * La fila de rotulos de la tabla de indicadores.
     *
     * @return list<string>
     */
    public static function header(): array
    {
        return array_map(
            static fn (string $column): string => self::text('columns.'.$column),
            self::COLUMNS,
        );
    }

    /**
     * La fila de rotulos del reparto por origen.
     *
     * @return list<string>
     */
    public static function originHeader(): array
    {
        return array_map(
            static fn (string $column): string => self::text('origin_columns.'.$column),
            self::ORIGIN_COLUMNS,
        );
    }

    /**
     * Una fila de indicador, en el mismo orden que {@see self::header()}.
     *
     * @return list<string>
     */
    public static function cells(AdoptionIndicator $indicator): array
    {
        return [
            self::text('indicators.'.$indicator->key->value),
            self::value($indicator->current, $indicator->unit),
            self::value($indicator->previous, $indicator->unit),
            self::delta($indicator->delta, $indicator->unit),
            self::target($indicator->target, $indicator->unit),
            self::status($indicator),
        ];
    }

    /**
     * Una fila del reparto por origen.
     *
     * @return list<string>
     */
    public static function originCells(AdoptionOriginShare $share): array
    {
        return [
            self::text('origins.'.$share->origin),
            (string) $share->scans,
            self::value($share->share, AdoptionIndicatorUnit::Percent),
        ];
    }

    /**
     * El bloque de cabecera del documento: rotulo y valor, en orden de lectura.
     *
     * **Va visible dentro del fichero, nunca en las propiedades del documento.**
     * `/informe-nuevo` lo exige por escrito y el motivo es practico: nadie abre las
     * propiedades de un XLSX, y el fichero se lee dos años despues sin nadie al
     * lado que lo explique.
     *
     * **El periodo anterior va en la cabecera y no solo implicito en una columna**:
     * es lo que permite saber contra que se esta comparando sin restar fechas a
     * mano, y es la primera cosa que alguien pregunta al ver una variacion.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function metadata(AdoptionReport $report, ?string $issuer, string $digest): array
    {
        return [
            [self::text('document.period'), $report->range->isoFrom().' → '.$report->range->isoTo()],
            [
                self::text('document.previous_period'),
                $report->previousRange->isoFrom().' → '.$report->previousRange->isoTo(),
            ],
            [self::text('document.time_zone'), $report->timeZone],
            [self::text('document.generated_at'), self::localInstant($report->generatedAt, $report->timeZone)],
            // El nombre de la cuenta emisora, nunca su correo (regla dura 12).
            [self::text('document.issuer'), $issuer ?? self::text('document.issuer_unknown')],
            [self::text('document.rows'), (string) $report->rowCount()],
            [self::text('document.digest'), $digest],
        ];
    }

    /**
     * Los criterios de inclusion, ya traducidos y en el orden en el que se leen.
     *
     * @return list<string>
     */
    public static function criteria(AdoptionReport $report): array
    {
        return array_map(
            static fn (string $key): string => self::text(self::withoutPrefix($key)),
            $report->criteria,
        );
    }

    /**
     * El nombre del fichero: producto, concepto y periodo. Nada mas.
     *
     * Dice `adopcion` y no `horas` para que no se confunda en una carpeta de
     * descargas con el informe por periodo, que puede llevar las mismas fechas y
     * otro contenido. **Sin nombre de persona y sin identificador** (regla dura 21),
     * que aqui es facil porque no hay ninguno que ocultar.
     */
    public static function filename(AdoptionReport $report, string $extension): string
    {
        return 'kronoqr-adopcion-'.$report->range->isoFrom().'_'.$report->range->isoTo().'.'.$extension;
    }

    /**
     * El instante de generacion en la zona del centro (ADR-040), no en UTC.
     *
     * Lo que se almacena es UTC (regla dura 3) y lo que lee una persona es la hora
     * que vivio: un cuadro sellado a las 05:12 cuando en el hotel eran las 07:12
     * parece generado por otro sistema. La zona se escribe al lado para que no haya
     * que adivinarla.
     */
    public static function localInstant(DateTimeImmutable $instant, string $timeZone): string
    {
        return $instant->setTimezone(new DateTimeZone($timeZone))->format('Y-m-d H:i');
    }

    /**
     * Un valor en su unidad, o el guion de «sin dato».
     *
     * **El guion y no una celda vacia**: una celda vacia en una hoja de calculo se
     * lee como un fallo de la exportacion, y lo que pasa de verdad es que no habia
     * denominador. Es la misma decision que en el cubo «Sin departamento» del
     * informe por periodo: el documento no tiene cliente que traduzca un nulo.
     */
    private static function value(?float $value, AdoptionIndicatorUnit $unit): string
    {
        if ($value === null) {
            return self::text('document.empty');
        }

        return match ($unit) {
            AdoptionIndicatorUnit::Percent => self::number($value, 2).' %',
            AdoptionIndicatorUnit::Minutes => ReportedDuration::ofMinutes((int) round($value))->toClockText(),
            AdoptionIndicatorUnit::Count => self::number($value, 0),
        };
    }

    /**
     * La variacion, **siempre con signo** y en la unidad del indicador.
     *
     * `pp` en los porcentajes: son puntos porcentuales y no por ciento, y la
     * diferencia importa —«+2,30 %» sobre un 97,1 % se lee como una mejora relativa
     * que no es lo que dice el numero—. `ReportedDuration` ya escribe el signo de
     * las duraciones, asi que ahi no se añade.
     */
    private static function delta(?float $delta, AdoptionIndicatorUnit $unit): string
    {
        if ($delta === null) {
            return self::text('document.empty');
        }

        return match ($unit) {
            AdoptionIndicatorUnit::Percent => self::signed($delta, 2).' pp',
            AdoptionIndicatorUnit::Minutes => self::signedDuration($delta),
            AdoptionIndicatorUnit::Count => self::signed($delta, 0),
        };
    }

    /**
     * Una variacion de duracion **con signo en los dos sentidos**: `+07:30`, `-12:30`.
     *
     * {@see ReportedDuration} escribe el `−` de una duracion negativa pero no el `+`
     * de una positiva —alli no hace falta, porque una desviacion positiva del informe
     * de horas se lee sola—. Aqui si: en una columna rotulada «Variacion», un `07:30`
     * a secas junto a un `-12:30` de la fila de arriba se lee como un valor absoluto,
     * y quien compara dos meses no sabe si el tiempo de resolucion subio o bajo.
     */
    private static function signedDuration(float $delta): string
    {
        $minutes = (int) round($delta);
        $text = ReportedDuration::ofMinutes($minutes)->toClockText();

        return $minutes > 0 ? '+'.$text : $text;
    }

    private static function target(?AdoptionTarget $target, AdoptionIndicatorUnit $unit): string
    {
        if ($target === null) {
            return self::text('target.none');
        }

        return self::text('target.'.$target->comparison->value, [
            'value' => match ($target->comparison) {
                // El objetivo de reduccion es un porcentaje de mejora sobre la
                // linea base, no un valor en la unidad del indicador: se escribe
                // como numero y la frase de `lang/` le pone el `%`.
                AdoptionTargetComparison::Reduction => self::number($target->value, 0),
                default => self::value($target->value, $unit),
            },
        ]);
    }

    private static function status(AdoptionIndicator $indicator): string
    {
        if ($indicator->target === null) {
            return self::text('status.no_target');
        }

        return match ($indicator->meetsTarget()) {
            true => self::text('status.met'),
            false => self::text('status.not_met'),
            // Sin valor, o con un objetivo que el producto no puede juzgar.
            // **No es «no cumple»**: ver el docblock de `AdoptionTarget`.
            null => self::text('status.unknown'),
        };
    }

    /**
     * Un numero con el separador decimal del idioma del documento.
     *
     * Coma en español y punto en ingles, por lo mismo que el separador del CSV: lo
     * abre una hoja de calculo con la configuracion regional del cliente, y un
     * `99.94` en un Excel español se lee como noventa y nueve mil novecientos
     * noventa y cuatro.
     */
    private static function number(float $value, int $decimals): string
    {
        return Lang::getLocale() === 'es'
            ? number_format($value, $decimals, ',', '.')
            : number_format($value, $decimals, '.', ',');
    }

    private static function signed(float $value, int $decimals): string
    {
        return ($value >= 0 ? '+' : '').self::number($value, $decimals);
    }

    /**
     * Las claves de criterio viajan con su prefijo `adoption.` desde el dominio,
     * para que el `Resource` las pueda traducir tal cual. Aqui el prefijo ya lo pone
     * {@see self::text()}, asi que se quita en un solo sitio.
     */
    private static function withoutPrefix(string $key): string
    {
        return str_starts_with($key, 'adoption.') ? substr($key, \strlen('adoption.')) : $key;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    public static function text(string $key, array $replacements = []): string
    {
        $line = Lang::get('reports.adoption.'.$key, $replacements);

        if (! \is_string($line) || $line === 'reports.adoption.'.$key) {
            throw new RuntimeException('Falta el texto «reports.adoption.'.$key.'» en lang/'.Lang::getLocale().'.');
        }

        return $line;
    }
}
