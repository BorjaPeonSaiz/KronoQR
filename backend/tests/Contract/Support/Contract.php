<?php

declare(strict_types=1);

namespace Tests\Contract\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lectura del contrato OpenAPI tal y como esta escrito en el fichero.
 *
 * Las pruebas de contrato de esta suite comprueban dos cosas distintas y por eso
 * hay dos formas de leerlo:
 *
 *   - Que **Spectator** puede cargarlo. Eso se prueba con el propio Spectator,
 *     porque lo que importa es que la herramienta que validara las respuestas en
 *     la tarea 1.7 entienda este fichero, no que un YAML cualquiera se parsee.
 *   - Que el contrato **dice lo que tiene que decir**. Eso se comprueba sobre el
 *     documento en crudo, sin el modelo de objetos de ninguna libreria: lo que se
 *     afirma en una prueba es lo que hay escrito en el fichero, no lo que una
 *     libreria haya decidido conservar al deserializarlo. La diferencia no es
 *     teorica: `cebe/php-openapi` descarta las palabras clave que no conoce, y
 *     una prueba escrita sobre su modelo daria por buena la ausencia de algo que
 *     simplemente no supo leer.
 *
 * El fichero se localiza por la MISMA configuracion que usa Spectator, asi que si
 * `config/spectator.php` apunta a un sitio equivocado, estas pruebas fallan en
 * vez de comprobar otra cosa.
 */
final class Contract
{
    /** @var array<mixed, mixed>|null */
    private static ?array $document = null;

    /**
     * Ruta absoluta del contrato, resuelta como la resuelve Spectator.
     */
    public static function file(): string
    {
        $base = config('spectator.sources.local.base_path');

        if (! is_string($base)) {
            throw new RuntimeException(
                'config(spectator.sources.local.base_path) no es una ruta. Revisa backend/config/spectator.php.'
            );
        }

        $file = realpath($base.'/openapi.yaml');

        if ($file === false) {
            throw new RuntimeException(
                'No se encuentra el contrato en '.$base.'/openapi.yaml. '
                .'Dentro del contenedor llega por el montaje ../docs de infra/compose.dev.yaml.'
            );
        }

        return $file;
    }

    /**
     * @return array<mixed, mixed>
     */
    public static function document(): array
    {
        if (self::$document !== null) {
            return self::$document;
        }

        $parsed = Yaml::parseFile(self::file());

        if (! is_array($parsed)) {
            throw new RuntimeException('El contrato no es un documento YAML valido.');
        }

        return self::$document = $parsed;
    }

    /**
     * Valor en la ruta indicada. Falla con la ruta recorrida, no con un null
     * silencioso: una prueba que compara null con null pasa sin comprobar nada.
     */
    public static function value(string ...$keys): mixed
    {
        $current = self::document();
        $walked = [];

        foreach ($keys as $key) {
            $walked[] = $key;

            if (! is_array($current) || ! array_key_exists($key, $current)) {
                throw new RuntimeException('El contrato no tiene '.implode(' > ', $walked).'.');
            }

            $current = $current[$key];
        }

        return $current;
    }

    public static function has(string ...$keys): bool
    {
        $current = self::document();

        foreach ($keys as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return false;
            }

            $current = $current[$key];
        }

        return true;
    }

    /**
     * @return array<mixed, mixed>
     */
    public static function map(string ...$keys): array
    {
        $value = self::value(...$keys);

        if (! is_array($value)) {
            throw new RuntimeException('El contrato tiene un escalar donde se esperaba un objeto: '.implode(' > ', $keys).'.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public static function keys(string ...$keys): array
    {
        return array_map(
            static fn (int|string $key): string => (string) $key,
            array_keys(self::map(...$keys)),
        );
    }

    public static function text(string ...$keys): string
    {
        $value = self::value(...$keys);

        if (! is_string($value)) {
            throw new RuntimeException('El contrato no tiene una cadena en '.implode(' > ', $keys).'.');
        }

        return $value;
    }

    /**
     * Los metodos HTTP que OpenAPI admite dentro de un `pathItem`.
     *
     * Se enumeran porque un `pathItem` puede llevar tambien `parameters`,
     * `summary`, `description` o `servers`, que **no son operaciones**. Sin esta
     * lista, un parametro declarado a nivel de ruta —lo normal cuando dos verbos
     * comparten el `{uuid}`— se contaria como una operacion sin `security` y la
     * prueba de la regla dura 18 fallaria por un motivo falso.
     *
     * @var list<string>
     */
    private const array HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * Todas las operaciones descritas, como pares ruta/metodo.
     *
     * @return list<array{path: string, method: string}>
     */
    public static function operations(): array
    {
        $operations = [];

        foreach (self::keys('paths') as $path) {
            foreach (self::keys('paths', $path) as $method) {
                if (! \in_array($method, self::HTTP_METHODS, true)) {
                    continue;
                }

                $operations[] = ['path' => $path, 'method' => $method];
            }
        }

        return $operations;
    }
}
