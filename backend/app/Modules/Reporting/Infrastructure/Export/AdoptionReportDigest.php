<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicator;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;

/**
 * La huella SHA-256 **del contenido** del cuadro de impacto (**RF-IN-08**).
 *
 * ## Que se firma, exactamente
 *
 * Una serializacion canonica de tres cosas, con el mismo criterio que
 * {@see PeriodReportDigest}:
 *
 *   1. Los dos periodos y la zona del centro.
 *   2. Los doce indicadores con sus cuatro cifras y su objetivo, por **clave sin
 *      traducir**.
 *   3. El reparto por origen.
 *
 * Y nada mas. En concreto **no entran** el instante de generacion, la cuenta que lo
 * pidio, el formato ni el idioma. La consecuencia es la que importa: **el CSV y el
 * PDF del mismo cuadro llevan la misma huella**, y dos PDF generados con dos
 * segundos de diferencia tambien. Si una correccion cambia las horas de marzo, la
 * huella cambia; si lo que cambia es quien lo imprimio, no.
 *
 * Los criterios **no entran**, al contrario que en el informe por periodo, y la
 * diferencia merece explicacion: alli el bloque de criterios varia con lo que se
 * pidio —«turnos abiertos incluidos», «N festivos»— y por tanto describe el
 * contenido; aqui las trece lineas son las mismas siempre, asi que incluirlas no
 * distinguiria dos cuadros y solo ataria la huella a un cambio de redaccion.
 *
 * ## El formato canonico, escrito para poder reproducirlo fuera
 *
 * UTF-8, lineas separadas por `\n`, campos separados por `\x1F` (el separador de
 * unidad de ASCII, que no puede aparecer en ninguno de los valores). Los nulos se
 * escriben como cadena vacia —que es lo que los distingue de un cero: `92.31` y
 * `` no son `0`—. Los valores van con el punto decimal y dos decimales fijos, sin
 * depender de la configuracion regional: la huella no puede cambiar porque el
 * documento se descargue en castellano.
 *
 * ```
 * kronoqr-adoption-report/1
 * range<US>2026-03-01<US>2026-03-31<US>2026-01-29<US>2026-02-28<US>Europe/Madrid
 * indicator<US>workdays_complete_ratio<US>percent<US>99.40<US>97.10<US>2.30<US>at_least<US>99.00
 * indicator<US>offline_resolved_ratio<US>percent<US>2.70<US><US><US><US>
 * origin<US>qr_kiosk<US>13847<US>98.60
 * …
 * ```
 *
 * La version de la primera linea existe para que el dia que haya que añadir un
 * indicador se pueda decir «esto es v2» en lugar de que las huellas empiecen a no
 * cuadrar sin explicacion.
 *
 * ## Por que vive aqui y no en el dominio
 *
 * Porque no es una regla de negocio: es como se **identifica** un documento que sale
 * de la instalacion. El dominio no sabe que existen los ficheros. Mismo criterio que
 * {@see AdoptionReportLayout}, que decide las columnas.
 */
final readonly class AdoptionReportDigest
{
    /** Version del formato canonico. Ver el docblock. */
    private const string VERSION = 'kronoqr-adoption-report/1';

    /** Separador de unidad de ASCII: no aparece en ningun valor del cuadro. */
    private const string SEPARATOR = "\x1F";

    private function __construct(public string $canonicalText, public string $sha256) {}

    public static function of(AdoptionReport $report): self
    {
        $text = self::canonicalize($report);

        return new self($text, hash('sha256', $text));
    }

    /**
     * Como se imprime en un pie de pagina o en un bloque de cabecera.
     *
     * En minusculas y sin separadores: es lo que se compara a ojo con otro papel, y
     * un grupo de cuatro con guiones invitaria a compararlo por trozos.
     */
    public function toText(): string
    {
        return $this->sha256;
    }

    private static function canonicalize(AdoptionReport $report): string
    {
        $lines = [
            self::VERSION,
            self::line([
                'range',
                $report->range->isoFrom(),
                $report->range->isoTo(),
                $report->previousRange->isoFrom(),
                $report->previousRange->isoTo(),
                $report->timeZone,
            ]),
        ];

        foreach ($report->indicators as $indicator) {
            $lines[] = self::line(self::indicator($indicator));
        }

        foreach ($report->originBreakdown as $share) {
            $lines[] = self::line(['origin', $share->origin, (string) $share->scans, self::decimal($share->share)]);
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function indicator(AdoptionIndicator $indicator): array
    {
        return [
            'indicator',
            $indicator->key->value,
            $indicator->unit->value,
            self::decimal($indicator->current),
            self::decimal($indicator->previous),
            self::decimal($indicator->delta),
            // El objetivo entra porque forma parte de lo que el documento AFIRMA:
            // dos cuadros con el mismo 98,6 % y objetivos distintos no dicen lo
            // mismo. Y porque el dia que el §1.3 cambiara una cifra, las huellas
            // tendrian que cambiar con ella.
            $indicator->target?->comparison->value ?? '',
            self::decimal($indicator->target?->value),
        ];
    }

    /**
     * Un numero con **punto** decimal y dos decimales fijos, o cadena vacia si es
     * nulo.
     *
     * El punto y no la coma para que el mismo cuadro descargado en castellano y en
     * ingles tenga la misma huella, por lo mismo que en el informe por periodo las
     * duraciones entran en minutos y no en `HH:MM`. Los dos decimales fijos para que
     * `99.4` y `99.40` no sean dos documentos distintos.
     */
    private static function decimal(?float $value): string
    {
        return $value === null ? '' : number_format($value, 2, '.', '');
    }

    /**
     * @param  list<string>  $fields
     */
    private static function line(array $fields): string
    {
        return implode(self::SEPARATOR, $fields);
    }
}
