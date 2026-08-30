<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Metrics;

use App\Modules\Compliance\Application\Port\LegalExportMetrics;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use Illuminate\Support\Facades\Log;
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
 * ## Nunca rompe una exportacion, pero tampoco calla
 *
 * Se llega aqui con el fichero escrito y el asiento de auditoria confirmado. Un
 * disco lleno no puede convertir eso en un error, porque quien exporto lo
 * repetiria y duplicaria el asiento. Por eso todo va envuelto y el fallo no
 * sube: lo que no se traga nunca es la auditoria, que corre antes y dentro de la
 * transaccion.
 *
 * Lo que si hace es **dejar constancia**. Este adaptador era el unico de los
 * siete que ni comprobaba el retorno de `rename()` ni miraba el interruptor del
 * colector en su propio metodo de escritura, y las dos cosas se ven igual desde
 * fuera: una serie que sigue publicando la cifra de ayer. Ahora la mecanica es
 * la de {@see TextfileExposition} —comun a los siete— y el fallo que esta clase
 * decide no propagar se escribe en el log con la clase de la excepcion y sin un
 * solo dato personal (regla dura 21).
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
        } catch (Throwable $exception) {
            // Ver el docblock: medir no puede romper una exportacion legal, pero
            // una metrica congelada tampoco puede pasar en silencio. El alcance
            // es `all` o `employee`, nunca un identificador (regla dura 21).
            Log::warning('compliance.legal_export_metric_not_published', [
                'scope' => $scope,
                'exception' => $exception::class,
            ]);
        }
    }

    private function publish(string $scope): void
    {
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

        TextfileExposition::write(self::FILE, $lines);
    }

    /**
     * @return array<string, int>
     */
    private function currentCounters(): array
    {
        $counters = array_fill_keys(self::SCOPES, 0);
        $target = TextfileExposition::path(self::FILE);

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
}
