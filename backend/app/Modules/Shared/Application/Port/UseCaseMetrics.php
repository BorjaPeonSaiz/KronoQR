<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * `use_case_duration_seconds{use_case,outcome}` (doc 02 §8.2).
 *
 * **Que dice esta metrica.** Cuanto tarda cada caso de uso y cuantas veces
 * termina en error, con una sola serie para todo el producto. Es la medida
 * RED de la capa de aplicacion: el borde HTTP ya tiene la suya, pero no ve los
 * casos de uso que entran por consola, por la cola o desde otro caso de uso
 * —la importacion de una plantilla, la correccion de la zona del centro—.
 *
 * **Dos etiquetas y ninguna identifica a nadie** (regla dura 21). `use_case`
 * es un literal estable del codigo (`workforce.register_employee`), nunca un
 * dato de la peticion; `outcome` es `ok`, `rejected` o `error`. La cardinalidad es
 * el numero de casos de uso por tres.
 *
 * Es un puerto y no una llamada directa por lo mismo que
 * {@see AuthenticationMetrics}: quien mide no sabe si detras hay Redis o nada.
 */
interface UseCaseMetrics
{
    /**
     * Una ejecucion mas del caso de uso, con su desenlace y su duracion.
     *
     * **Medir no puede romper lo medido.** La implementacion traga cualquier
     * fallo del almacen y **descarta** la observacion cuyo `use_case` u
     * `outcome` no tenga la forma esperada en lugar de lanzar.
     *
     * @param  string  $useCase  `<modulo>.<accion_snake>`.
     * @param  string  $outcome  `ok`, `rejected` o `error`.
     */
    public function observe(string $useCase, string $outcome, float $seconds): void;
}
