<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * ¿Dos raices de clase se pisan? (ADR-045, condicion C3).
 *
 * Dos clases que comparten raiz, o una dentro de otra, rompen el confinamiento:
 * la purga de una veria las entradas de la otra. El patron exacto de nombre
 * ya lo impide hoy, pero es la ultima linea; `product:doctor` y la prueba de
 * inventario fallan antes, en la configuracion.
 *
 * ## Comparacion lexica
 *
 * Sobre rutas absolutas normalizadas —sin `.`, sin `..` y sin barras repetidas—
 * y por segmentos completos: `/srv/app/exports` no contiene a
 * `/srv/app/exports-old`. Resolver enlaces simbolicos es cosa del adaptador,
 * que los resuelve con `realpath` antes de llamar aqui cuando la ruta existe.
 */
final class PathOverlap
{
    /** No se instancia: dos funciones puras. */
    private function __construct() {}

    /** Coinciden, o una contiene a la otra. */
    public static function between(string $first, string $second): bool
    {
        return self::contains($first, $second) || self::contains($second, $first);
    }

    /** `$outer` es `$inner` o un ascendiente suyo. */
    public static function contains(string $outer, string $inner): bool
    {
        $outer = self::normalise($outer);
        $inner = self::normalise($inner);

        if ($outer === $inner) {
            return true;
        }

        $prefix = $outer === '/' ? '/' : $outer.'/';

        return str_starts_with($inner, $prefix);
    }

    /**
     * La forma canonica de una ruta: separadores `/`, sin `.` ni `..` y sin barra
     * final. Una ruta relativa se trata igual, sin inventarle un directorio de
     * trabajo.
     */
    public static function normalise(string $path): string
    {
        $absolute = str_starts_with(str_replace('\\', '/', $path), '/');
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return ($absolute ? '/' : '').implode('/', $segments);
    }
}
