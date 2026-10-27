<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * Produce el identificador de un registro nuevo: un UUID v7 en su forma
 * canonica de 36 caracteres en minusculas (doc 02 §6).
 *
 * **Es un puerto por la misma razon que `Clock`** (regla dura 2, ADR-021,
 * RV-3): un UUID v7 lleva dentro el instante en que se genera, asi que
 * generarlo es leer el reloj del sistema, y ademas es azar. Antes los casos de
 * uso llamaban a `Str::uuid7()`, un helper del framework que ADR-002 deja fuera
 * de `Application/`. Ahora lo piden aqui, y una prueba puede fijarlos.
 *
 * v7 y no v4: es ordenable en el tiempo, lo que mantiene la localidad de los
 * indices que lo referencian, y no dice nada de cuantos registros hay.
 *
 * Vive en Shared porque lo piden casos de uso de cinco modulos que no pueden
 * importarse entre si (criterio de admision de ADR-021). La segunda
 * implementacion que justifica la interfaz es el doble determinista de las
 * pruebas (doc 02 §3.5).
 */
interface UuidGenerator
{
    public function next(): string;
}
