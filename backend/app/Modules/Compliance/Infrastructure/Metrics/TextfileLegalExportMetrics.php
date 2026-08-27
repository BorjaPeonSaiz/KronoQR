<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Metrics;

use App\Modules\Compliance\Application\Port\LegalExportMetrics;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * `legal_exports_total{scope}` para el colector *textfile* de `node-exporter`
 * (doc 02 §8.2, RF-IN-05).
 *
 * ## Por que textfile y no un contador en memoria
 *
 * Igual que {@see TextfileAuditMetrics}: la aplicacion corre en PHP-FPM, donde
 * cada peticion es un proceso nuevo. Un contador en memoria vuelve a cero
 * constantemente. El fichero `.prom` sobrevive a los procesos y lo recoge
 * `node-exporter`, que es la via que el §8.2 elige para todo lo que no es una
 * metrica de peticion.
 *
 * ## Se acumula leyendo el valor anterior
 *
 * Es un `counter`. Se lee lo que hay, se suma uno a la etiqueta que toca y se
 * reescribe entero —las dos series, siempre las dos—: una serie que desaparece
 * es indistinguible de una que nunca ocurrio, y `legal_exports_total{scope="all"}`
 * a cero es informacion.
 *
 * ## Nunca rompe una exportacion
 *
 * Se llega aqui con el fichero escrito y el asiento de auditoria confirmado. Un
 * disco lleno no puede convertir eso en un error, porque quien exporto lo
 * repetiria y duplicaria el asiento. Por eso todo va envuelto y el fallo se
 * traga: lo que no se traga nunca es la auditoria, que corre antes y dentro de
 * la transaccion.
 *
 * ## La etiqueta es `all` o `employee`
 *
 * Nunca un identificador (regla dura 21). Un UUID en una etiqueta de Prometheus
 * crea una serie temporal por persona exportada: una fuga hacia el sistema de
 * metricas y una explosion de cardinalidad a la vez. Lo decide
 * {@see LegalExportScope::metricLabel()}.
 */
final readonly class TextfileLegalExportMetrics implements LegalExportMetrics
{
    private const string FILE = 'kronoqr_legal_exports.prom';

    private const string METRIC = 'legal_exports_total';

    /**
     * Las etiquetas que se publican siempre, esten a cero o no.
     *
     * @var list<string>
     */
    private const array SCOPES = ['all', 'employee'];

    public function exportGenerated(string $scope): void
    {
        try {
            $this->publish($scope);
        } catch (Throwable) {
            // Ver el docblock: medir no puede romper una exportacion legal.
        }
    }

    private function publish(string $scope): void
    {
        if (! Config::boolean('observability.metrics.enabled', true)) {
            return;
        }

        $counters = $this->currentCounters();

        if (array_key_exists($scope, $counters)) {
            $counters[$scope]++;
        }

        $lines = [
            '# HELP '.self::METRIC.' Exportaciones normalizadas para la Inspeccion de Trabajo generadas (RF-IN-05).',
            '# TYPE '.self::METRIC.' counter',
        ];

        foreach (self::SCOPES as $label) {
            $lines[] = self::METRIC.'{scope="'.$label.'"} '.$counters[$label];
        }

        $this->write($lines);
    }

    /**
     * @return array<string, int>
     */
    private function currentCounters(): array
    {
        $counters = array_fill_keys(self::SCOPES, 0);
        $target = $this->directory().'/'.self::FILE;

        if (! is_file($target)) {
            return $counters;
        }

        $contents = file_get_contents($target);

        if ($contents === false) {
            return $counters;
        }

        foreach (self::SCOPES as $label) {
            // Anclada al principio de linea para no confundirse con `# HELP` y
            // `# TYPE`, que contienen el mismo nombre de metrica.
            $pattern = '/^'.preg_quote(self::METRIC.'{scope="'.$label.'"}', '/').'\s+(\d+)/m';

            if (preg_match($pattern, $contents, $match) === 1) {
                $counters[$label] = (int) $match[1];
            }
        }

        return $counters;
    }

    /**
     * Escritura atomica: temporal en el mismo directorio y `rename()`.
     * `node-exporter` no puede leer media metrica.
     *
     * @param  list<string>  $lines
     */
    private function write(array $lines): void
    {
        $directory = $this->directory();

        if (! is_dir($directory) && ! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            return;
        }

        $target = $directory.'/'.self::FILE;
        $temporary = $target.'.tmp';

        if (file_put_contents($temporary, implode("\n", $lines)."\n") === false) {
            return;
        }

        rename($temporary, $target);
    }

    private function directory(): string
    {
        return rtrim(Config::string('observability.metrics.textfile_path'), '/');
    }
}
