<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * El bloque literal de series del doc 02 §8.2, leido una sola vez para toda la
 * suite.
 *
 * ## Por que vive aqui y no en cada prueba
 *
 * Dos pruebas necesitan el mismo listado y por motivos distintos:
 * `MetricsCatalogueTest` compara el catalogo de `/metrics` contra el documento,
 * y `GrafanaDashboardsTest` comprueba que ninguna consulta de un cuadro nombra
 * una serie que el documento no declara. Cada una tenia su copia de la misma
 * expresion regular, y una copia se corrige en un sitio y se queda vieja en el
 * otro (decision 17k de la ficha 3.2).
 *
 * En una clase de `Support/` y no como funcion global de un fichero de prueba
 * por el motivo que explica {@see Repo}: una funcion declarada dentro de un
 * `*Test.php` solo existe si Pest ya cargo ese fichero, y con `--filter` el
 * orden de carga cambia. Una prueba que pasa entera y falla filtrada no
 * verifica nada.
 *
 * ## Que se lee y que no
 *
 * Solo el bloque de codigo del apartado, que es la **columna literal** que la
 * ficha manda respetar. Si el apartado cambia de titulo o pierde el bloque, los
 * lectores devuelven un conjunto vacio y las guardas de cada prueba —que
 * exigen una cota inferior de series— lo convierten en un fallo con mensaje, no
 * en un verde silencioso comparando dos conjuntos vacios.
 */
final class Doc82Series
{
    public const HEADING = '### 8.2 Métricas expuestas';

    /**
     * Las series con su tipo y sus etiquetas, en el orden en que las declara el
     * documento.
     *
     * El ORDEN de las etiquetas importa: es el orden en el que el adaptador las
     * concatena en Redis (`site=1,department=Cocina`) y el que el lector usa
     * para descomponerlas.
     *
     * @return array<string, array{type: string, labels: list<string>}>
     */
    public static function withTypes(): array
    {
        preg_match_all(
            '/^([a-z][a-z0-9_]*)(?:\{([a-z0-9_,]*)\})?[ \t]+(counter|gauge|histogram)$/m',
            self::listing(),
            $matches,
            PREG_SET_ORDER,
        );

        $series = [];

        foreach ($matches as $match) {
            $series[$match[1]] = [
                'type' => $match[3],
                'labels' => $match[2] === '' ? [] : explode(',', $match[2]),
            ];
        }

        return $series;
    }

    /**
     * Los nombres de las series, mas las derivadas de cada histograma.
     *
     * Prometheus genera `_bucket`, `_sum` y `_count` a partir de un histograma y
     * el documento no las lista una a una porque no las escribe nadie: una
     * consulta que use `http_request_duration_seconds_bucket` esta usando una
     * serie documentada.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (self::withTypes() as $name => $definition) {
            $names[] = $name;

            if ($definition['type'] !== 'histogram') {
                continue;
            }

            $names[] = $name.'_bucket';
            $names[] = $name.'_sum';
            $names[] = $name.'_count';
        }

        return $names;
    }

    /** El texto del bloque de codigo del §8.2, sin las vallas. */
    private static function listing(): string
    {
        $document = str_replace("\r\n", "\n", Repo::contents('docs/02-stack-tecnologico-y-plan-implementacion.md'));

        $section = strstr($document, self::HEADING);

        if ($section === false) {
            return '';
        }

        preg_match('/```\n(.*?)\n```/s', $section, $block);

        return $block[1] ?? '';
    }
}
