<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * Las claves de entorno que el producto DECLARA (`.env.example`) y las que su
 * configuracion LEE (`env('X')` en `config/`), para cruzarlas con lo que Compose
 * entrega a cada contenedor (ADR-042 §4, ADR-029).
 *
 * Sin este cruce, quitar `env_file: .env` convierte cada variable olvidada en un
 * fallo silencioso: el cliente la pone en su `.env`, Compose no la entrega y la
 * aplicacion sigue con el valor por defecto del codigo sin que nadie lo note.
 */
final class DeclaredEnvironment
{
    /**
     * Las claves de `.env.example`: las lineas activas (`CLAVE=`) y las de
     * configuracion comentada (`# CLAVE=valor`, con o sin nota detras de otro
     * `#`).
     *
     * LA PROSA NO CUENTA. `.env.example` explica variables en frases que
     * empiezan igual —«# PORTAL_INTERNAL_ONLY=true sin que nada la leyera; se
     * retiro…»—, y tratarlas como claves obligaria a entregar al runtime una
     * variable que ya no existe. Una linea comentada es configuracion solo si
     * detras del valor no hay mas que espacios o una nota que empieza por `#`.
     *
     * @return list<string>
     */
    public static function keysInEnvExample(string $contents): array
    {
        $keys = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $matched = preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $active) === 1
                ? $active
                : (preg_match('/^#\s?([A-Z][A-Z0-9_]*)=\S*\s*(?:#.*)?$/', $line, $commented) === 1 ? $commented : null);

            if ($matched !== null) {
                $keys[] = $matched[1];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Los nombres que un fichero de configuracion lee con `env('X'…)` o
     * `env("X"…)`.
     *
     * @return list<string>
     */
    public static function readBySource(string $source): array
    {
        preg_match_all('/\benv\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Los ficheros de configuracion que la aplicacion carga en produccion:
     * `backend/config/*.php` y los `config/*.php` de paquetes que NO estan
     * publicados en `backend/config` (Laravel los funde igual: los del
     * framework, `laravel/horizon`, `spatie/laravel-pdf`…).
     *
     * `scandir` y no un iterador recursivo: `backend/config` va por el bind mount
     * de Docker Desktop, que pierde ficheros al recorrerlo con iteradores.
     *
     * @return list<string>
     */
    public static function configFiles(): array
    {
        $backend = \dirname(ModuleTree::root(), 2);
        $published = array_values(array_filter(
            scandir($backend.'/config') ?: [],
            static fn (string $file): bool => str_ends_with($file, '.php'),
        ));

        $packages = array_filter(
            glob($backend.'/vendor/*/*/config/*.php') ?: [],
            static fn (string $file): bool => ! \in_array(basename($file), $published, true),
        );

        return [
            ...array_map(static fn (string $file): string => $backend.'/config/'.$file, $published),
            ...array_values($packages),
        ];
    }

    /**
     * Todo lo que la configuracion cargada lee del entorno.
     *
     * @return list<string>
     */
    public static function readByConfig(): array
    {
        $names = [];

        foreach (self::configFiles() as $file) {
            $names = [...$names, ...self::readBySource((string) file_get_contents($file))];
        }

        return array_values(array_unique($names));
    }
}
