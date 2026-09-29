<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lo que cada servicio de un fichero de Compose recibe en su entorno, leido del
 * YAML ya COMPUESTO y no del texto.
 *
 * POR QUE PARSEAR Y NO BUSCAR CADENAS. Desde ADR-042 el entorno del runtime se
 * declara en anclas (`x-runtime-env`, `x-reverb-env`) que los servicios funden
 * con `<<:`. Buscar `DB_MIGRATION_PASSWORD` en el bloque de texto de `app` no
 * veria la variable si llegara por el ancla, y buscarla en el fichero entero no
 * diria a que servicio llega. `symfony/yaml` resuelve anclas, alias y `<<` con
 * la misma semantica que Compose: la fusion es superficial y las claves propias
 * del servicio ganan a las fundidas.
 *
 * DOS PREGUNTAS DISTINTAS, DOS FUNCIONES.
 *
 *   · `environmentNames()`: lo que llega al ENTORNO DEL PROCESO. Las claves de
 *     `environment:` y las variables que Compose interpola en sus valores
 *     (`PGOPTIONS: "-c lock_timeout=${DB_LOCK_TIMEOUT:-5s}"` usa
 *     `DB_LOCK_TIMEOUT`). Es lo que responde «¿la aplicacion puede leer esta
 *     clave?».
 *   · `referencedNames()`: todo lo que el servicio NOMBRA, en cualquier sitio de
 *     su definicion —entorno, volumenes, orden, sonda—. Una credencial
 *     interpolada en un `command:` tambien acaba en `docker inspect`. Es lo que
 *     responde «¿este contenedor puede llegar a ver esta credencial?».
 */
final class ComposeEnvironment
{
    /**
     * Los servicios del fichero, con anclas y `<<` ya resueltos.
     *
     * @return array<string, array<mixed>>
     */
    public static function services(string $relative): array
    {
        $document = Yaml::parse(Repo::contents($relative));
        $services = \is_array($document) ? ($document['services'] ?? null) : null;

        if (! \is_array($services) || $services === []) {
            throw new RuntimeException($relative.' no declara ningun servicio: la prueba no tendria nada que mirar.');
        }

        $typed = [];

        foreach ($services as $name => $definition) {
            $typed[(string) $name] = \is_array($definition) ? $definition : [];
        }

        return $typed;
    }

    /**
     * Un servicio concreto. Si no existe, falla diciendo cual: un servicio
     * renombrado no puede convertir las comprobaciones en verdes vacios.
     *
     * @return array<mixed>
     */
    public static function service(string $relative, string $name): array
    {
        return self::services($relative)[$name]
            ?? throw new RuntimeException("{$relative} no tiene el servicio «{$name}».");
    }

    /**
     * `environment:` normalizado a mapa, admita Compose la forma de mapa
     * (`X:` / `X: valor`) o la de lista (`- X` / `- X=valor`).
     *
     * @param  array<mixed>  $service
     * @return array<string, string|null>
     */
    public static function environment(array $service): array
    {
        $declared = $service['environment'] ?? [];

        if (! \is_array($declared)) {
            return [];
        }

        $environment = [];

        foreach ($declared as $key => $value) {
            if (\is_int($key)) {
                $parts = explode('=', self::scalar($value), 2);
                $environment[$parts[0]] = $parts[1] ?? null;

                continue;
            }

            $environment[$key] = $value === null ? null : self::scalar($value);
        }

        return $environment;
    }

    /**
     * Lo que llega al entorno del proceso: claves y variables interpoladas en
     * sus valores.
     *
     * @param  array<mixed>  $service
     * @return list<string>
     */
    public static function environmentNames(array $service): array
    {
        $environment = self::environment($service);
        $names = array_keys($environment);

        foreach ($environment as $value) {
            $names = [...$names, ...self::interpolatedIn((string) $value)];
        }

        return array_values(array_unique($names));
    }

    /**
     * Todo lo que el servicio nombra, en cualquier parte de su definicion.
     *
     * @param  array<mixed>  $service
     * @return list<string>
     */
    public static function referencedNames(array $service): array
    {
        $names = self::environmentNames($service);

        array_walk_recursive($service, static function (mixed $value) use (&$names): void {
            if (\is_string($value)) {
                $names = [...$names, ...self::interpolatedIn($value)];
            }
        });

        return array_values(array_unique($names));
    }

    /**
     * Las variables que Compose interpola en un texto: `$X`, `${X}`, `${X:-d}`,
     * `${X:?mensaje}`. `$$` es un dolar literal para el contenedor y NO es una
     * interpolacion: `$${POSTGRES_USER}` en una sonda lo resuelve el shell del
     * contenedor, no Compose.
     *
     * @return list<string>
     */
    public static function interpolatedIn(string $text): array
    {
        preg_match_all('/(?<!\$)\$\{?([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    private static function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
