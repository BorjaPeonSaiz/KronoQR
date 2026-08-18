<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use RuntimeException;

/**
 * Localiza la raiz del repositorio, que NO es la del backend, y lee ficheros
 * relativos a ella.
 *
 * Dentro del contenedor solo esta montado `backend/` (en /var/www/html), asi
 * que la raiz llega por un montaje aparte de solo lectura. En la CI, que corre
 * sobre el arbol completo sin contenedor, es el directorio padre de `backend/`.
 * Las dos rutas se resuelven aqui para que la suite de el mismo resultado en
 * los dos sitios: una prueba que pasa en la CI y falla en local no verifica
 * nada, solo enseña donde se ejecuto.
 *
 * Vive en una clase y no como funcion suelta de un fichero de prueba porque la
 * usan varios: una funcion global declarada dentro de un `*Test.php` solo esta
 * disponible si ese fichero ya se cargo, y el orden de carga de Pest no es algo
 * sobre lo que convenga construir nada.
 */
final class Repo
{
    public static function root(): string
    {
        // Se busca por MARCA y no contando niveles de directorio. La primera
        // version contaba —y contaba mal—: dentro del contenedor el montaje
        // /var/www/repo tapaba el error, y en la CI, que corre sobre el arbol
        // completo, resolvia a <repo>/backend y buscaba backend/backend/phpstan.neon.
        //
        // Las nueve pruebas pasaban en local y fallaban en el runner, que es
        // exactamente lo que este ayudante existe para evitar. Contar niveles es
        // fragil ante cualquier cambio de ubicacion; una marca no.
        $candidates = ['/var/www/repo', ...array_map(
            static fn (int $levels): string => \dirname(ModuleTree::root(), $levels),
            [2, 3, 4],
        )];

        foreach ($candidates as $candidate) {
            if (is_file($candidate.'/Makefile') && is_dir($candidate.'/docs')) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'No se encuentra la raiz del repositorio. Se reconoce por tener Makefile y docs/. '
            .'Dentro del contenedor llega montada en /var/www/repo (infra/compose.dev.yaml); '
            .'en la CI es el directorio padre de backend/.'
        );
    }

    /** Ruta absoluta de un fichero del repositorio, relativa a su raiz. */
    public static function file(string $relative): string
    {
        return self::root().'/'.ltrim($relative, '/');
    }

    public static function contents(string $relative): string
    {
        $path = self::file($relative);

        if (! is_file($path)) {
            throw new RuntimeException($relative.' no existe en el repositorio.');
        }

        return (string) file_get_contents($path);
    }
}
