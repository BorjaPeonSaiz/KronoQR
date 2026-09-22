<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use RuntimeException;

/**
 * Lectura de los artefactos de la revision de seguridad (`docs/seguridad/`).
 *
 * POR QUE ESTA CLASE EXISTE. Por lo mismo que {@see ClientDocs}: las pruebas de
 * `SecurityReviewEvidenceTest` no pueden llevar bucles ni condicionales —un `if`
 * dentro de una prueba es una rama que nadie prueba, y un bucle esconde cual de
 * los elementos fallo—, asi que el recorrido del directorio, el troceado de las
 * tablas y la resolucion de los enlaces viven aqui y cada prueba se queda con
 * una lista y una aseveracion.
 *
 * ESTOS DOCUMENTOS NO VIAJAN AL CLIENTE. `infra/scripts/package.sh` copia solo
 * `docs/cliente` y `docs/runbooks`; que `docs/seguridad` siga fuera es una de
 * las cosas que la prueba comprueba, porque aqui esta el detalle de los
 * hallazgos.
 */
final class SecurityReview
{
    /** Donde viven los informes de revision (decision 2 de la ficha 3.8). */
    public const string ROOT = 'docs/seguridad';

    /** El indice del paquete que se entrega al revisor externo. */
    public const string PACKAGE_INDEX = self::ROOT.'/paquete-revisor.md';

    /**
     * Cuantos dias, como minimo, separan un informe de la fecha limite de la
     * siguiente revision. RS-11 dice «periodicidad anual» y 365 es el numero
     * explicito de esa frase; se escribe aqui y no se calcula para que la prueba
     * no dependa de la aritmetica de meses.
     */
    public const int ANNUAL_PERIOD_DAYS = 365;

    /**
     * Los informes de revision interna, en rutas relativas a la raiz del
     * repositorio y ordenados: el ultimo de la lista es el mas reciente, porque
     * el nombre lleva la fecha en `AAAA-MM`.
     *
     * Solo cuentan los que llevan la fecha en el nombre. Un
     * `revision-interna-asvs-borrador.md` no es un informe: es un borrador, y
     * dar por cumplida la periodicidad con el seria justo lo que RS-11 quiere
     * evitar.
     *
     * @return list<string>
     */
    public static function reports(): array
    {
        $reports = [];

        foreach (glob(Repo::file(self::ROOT).'/revision-interna-asvs-*.md') ?: [] as $path) {
            $name = basename($path);
            $reports[] = preg_match('/^revision-interna-asvs-\d{4}-\d{2}\.md$/', $name) === 1
                ? self::ROOT.'/'.$name
                : null;
        }

        $reports = array_values(array_filter($reports));
        sort($reports);

        return $reports;
    }

    /**
     * El informe mas reciente. Revienta si no hay ninguno, para que el fallo
     * hable de lo que falta y no de un indice inexistente.
     */
    public static function latestReport(): string
    {
        $reports = self::reports();

        if ($reports === []) {
            throw new RuntimeException(
                'No hay ningun informe `'.self::ROOT.'/revision-interna-asvs-AAAA-MM.md`: RS-11 se queda sin '
                .'evidencia de que la preparacion de la revision exista.',
            );
        }

        return $reports[\count($reports) - 1];
    }

    /** Contenido de un documento, con los finales de linea normalizados. */
    public static function contents(string $relative): string
    {
        return str_replace("\r\n", "\n", Repo::contents($relative));
    }

    /**
     * De una lista de textos, los que el documento NO contiene.
     *
     * @param  list<string>  $needles
     * @return list<string>
     */
    public static function missingFrom(array $needles, string $relative): array
    {
        $content = self::contents($relative);
        $missing = [];

        foreach ($needles as $needle) {
            $missing[] = str_contains($content, $needle) ? null : $needle;
        }

        return array_values(array_filter($missing));
    }

    /**
     * La fecha de cabecera del informe: la fila `| **Fecha** | AAAA-MM-DD |`.
     *
     * Es la fecha de REFERENCIA de las comprobaciones de caducidad, y se lee del
     * propio documento a proposito: asi la periodicidad se mide contra lo que el
     * informe declara y no contra el dia en que se ejecuta la suite.
     */
    public static function reportDate(string $relative): string
    {
        preg_match('/^\|\s*\*\*Fecha\*\*\s*\|\s*(\d{4}-\d{2}-\d{2})\s*\|/m', self::contents($relative), $match);

        return $match[1] ?? '';
    }

    /**
     * La fecha limite de la siguiente revision anual, tal y como la declara la
     * fila «Siguiente revisión anual» del indice del paquete.
     *
     * Cadena vacia si la fila no esta o no declara limite, para que la prueba lo
     * diga con su mensaje en vez de reventar.
     */
    public static function nextReviewDeadline(): string
    {
        preg_match(
            '/^\|[^|]*Siguiente revisión anual[^|]*\|[^|]*límite:\s*\*{0,2}(\d{4}-\d{2}-\d{2})\*{0,2}[^|]*\|/mu',
            self::contents(self::PACKAGE_INDEX),
            $match,
        );

        return $match[1] ?? '';
    }

    /**
     * Los enlaces relativos de un documento que no resuelven a ningun fichero ni
     * directorio del repositorio.
     *
     * Se aceptan los directorios —`docs/adr/` y `evidencia/` son entregables del
     * paquete y son directorios— al contrario que en `ClientDocs`, que solo mira
     * `.md`. Las absolutas y las `http` quedan fuera: un enlace a la web del
     * fabricante es legitimo.
     *
     * @return list<string>
     */
    public static function brokenLinks(string $relative): array
    {
        preg_match_all('/\]\(([^)\s]+)\)/', self::contents($relative), $matches);

        $base = \dirname(Repo::file($relative));
        $broken = [];

        foreach (array_unique($matches[1]) as $target) {
            $externo = str_starts_with($target, 'http') || str_starts_with($target, '/') || str_starts_with($target, '#');
            $resuelve = $externo || file_exists($base.'/'.explode('#', $target)[0]);

            $broken[] = $resuelve ? null : $relative.' -> '.$target;
        }

        $broken = array_values(array_filter($broken));
        sort($broken);

        return $broken;
    }
}
