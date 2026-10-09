<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

/**
 * Lee una serie de un fichero `.prom` del colector *textfile* (RF-PD-13).
 *
 * ## Para que
 *
 * `backup.sh` y `wal-metrics.sh` publican su resultado en
 * `BACKUP_PATH/metrics/*.prom` para `node-exporter`. Es la unica constancia que
 * existe de cuando fue la ultima copia buena y de si el planificador sigue
 * lanzando tareas, y `product:doctor` la lee **del mismo fichero** que las
 * alertas: si el informe y la alerta midieran cosas distintas, dirian cosas
 * distintas del mismo problema.
 *
 * ## Por que no se confia en el contenido
 *
 * `metrics/` lo escriben tambien `app` y `horizon` (bloque 20, A3-R2). El
 * fichero se trata como entrada no confiable: tamaño acotado, una expresion
 * regular de numeros y nada mas —ni `eval` ni deserializacion—, igual que hace
 * `wal-metrics.sh` con su estado. Un proceso comprometido puede falsear el
 * valor (residuo declarado en ADR-049); lo que no puede es hacer que el
 * diagnostico ejecute nada ni reviente.
 *
 * Nunca lanza.
 */
final readonly class TextfileMetricsReader
{
    /** Un `.prom` de estas tareas son unos cientos de bytes; 64 KiB es holgura de sobra. */
    private const int MAX_BYTES = 65536;

    public function __construct(private string $directory) {}

    /**
     * La ruta completa de un fichero del directorio de metricas, para los detalles del informe.
     */
    public function path(string $file): string
    {
        return rtrim($this->directory, '/').'/'.$file;
    }

    /**
     * `missing` si el fichero no existe, `unreadable` si existe y no se puede leer, `readable` si se puede.
     */
    public function state(string $file): string
    {
        $path = $this->path($file);

        if (! is_file($path)) {
            return 'missing';
        }

        return is_readable($path) ? 'readable' : 'unreadable';
    }

    /**
     * El valor de la primera muestra de `$metric` (con o sin etiquetas), o
     * `null` si el fichero no existe, no se puede leer o no trae la serie.
     */
    public function value(string $file, string $metric): ?float
    {
        if ($this->state($file) !== 'readable') {
            return null;
        }

        $content = @file_get_contents($this->path($file), false, null, 0, self::MAX_BYTES);

        if (! \is_string($content)) {
            return null;
        }

        $pattern = '/^'.preg_quote($metric, '/').'(?:\{[^}\n]*\})?[ \t]+(-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)[ \t]*$/m';

        if (preg_match($pattern, $content, $match) !== 1) {
            return null;
        }

        return (float) $match[1];
    }
}
