<?php

declare(strict_types=1);

namespace Tests\MutationCanary;

/**
 * El canario de la mutacion (R2-QB-06 de la 2.2.0, tarea 22.5 de la 2.3.0).
 *
 * Pest Mutate cuenta como «tested» —mutante muerto— CUALQUIER salida distinta
 * de cero del proceso hijo que ejecuta las pruebas contra el mutante
 * (`Pest\Mutate\MutationTest::hasFinished`), incluidos los fallos del propio
 * entorno: memoria, una extension, una opcion que el hijo no entiende. Con
 * `--processes=8` la mutacion de `Kiosk/Domain` llego a dar `188 tested,
 * 100,00 %` cuando la cifra real era 87,77 %. Un MSI del 100 % por un entorno
 * roto es indistinguible de uno bueno.
 *
 * Esta clase existe para que eso se vea. `make mutate-canary` la muta con las
 * MISMAS opciones que el dominio y exige un resultado conocido:
 *
 * - `next()` tiene una prueba que afirma el valor exacto, asi que sus
 *   mutantes TIENEN que morir. Si sobreviven, el mutante no llega al hijo
 *   (OPcache sirviendo el original, por ejemplo) y el MSI estaria midiendo
 *   el codigo sin mutar.
 * - `isPositive()` tiene una prueba que solo mira el tipo, asi que sus
 *   mutantes TIENEN que sobrevivir. Si mueren, el hijo esta fallando por su
 *   cuenta y cada mutante del dominio cuenta como muerto sin serlo.
 *
 * No es codigo del producto ni lo usa nadie: vive en `tests/` y solo la
 * declaran la suite `MutationCanary` y el `<source>` de `phpunit.xml`.
 */
final class MutationCanary
{
    public static function next(int $value): int
    {
        return $value + 1;
    }

    public static function isPositive(int $value): bool
    {
        return $value > 0;
    }
}
