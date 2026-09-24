<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * Cuantas jornadas de un dia quedaron **completas**, por centro (RF-IN-08).
 *
 * Es la lectura que sostiene `workdays_complete_ratio{site}` del §8.2, el
 * indicador con el que el cuadro de «Impacto y adopcion» contesta a *«¿esto
 * esta sirviendo para algo?»*: si el registro horario que la empresa tiene que
 * poder enseñar a Inspeccion se esta produciendo solo, o si media plantilla
 * termina el mes con turnos sin cerrar.
 *
 * **Que cuenta como completa.** Una jornada —un empleado y una fecha— con al
 * menos un tramo y **ninguno abierto**. La entrada sin salida es justo el
 * defecto que RN-08 prohibe cerrar de oficio y que acaba en una correccion
 * manual, asi que es lo que este indicador tiene que ver.
 *
 * **Que no cuenta ni en el numerador ni en el denominador.** Los tramos
 * anulados y los sustituidos por una correccion: el registro vigente es el de
 * la ultima version (RN-13), y contar las anteriores haria que corregir bien un
 * dia lo empeorara en el indicador.
 *
 * **Sin `employee_uuid` ni nada parecido.** Lo que sale de aqui son dos
 * recuentos por centro. La pregunta «¿quien se deja el turno abierto?» tiene su
 * sitio —la bandeja de incidencias, con control de acceso y retencion—, y no es
 * una serie de Prometheus (regla dura 21).
 */
interface WorkDayCompletionReader
{
    /**
     * @param  string  $workDate  Fecha de la jornada en `Y-m-d`, ya resuelta en la
     *                            zona del centro por quien llama: `work_date` es una
     *                            fecha civil y un turno de noche pertenece a la jornada
     *                            de su hora de INICIO (RN-05, regla dura 4).
     * @return array<int, array{complete: int, total: int}> Indexado por identificador de
     *                                                      centro. Un centro sin ninguna
     *                                                      jornada ese dia **no aparece**.
     */
    public function completionOn(string $workDate): array;

    /**
     * Lo mismo para un **rango** de jornadas, con la misma definicion exacta
     * (RF-IN-08, tarea 3.13).
     *
     * ## Por que un metodo y no llamar al de arriba en un bucle
     *
     * Porque el cuadro de impacto pide periodos de hasta un año: 366 consultas por
     * periodo y dos periodos por cuadro son 732 idas y vueltas a PostgreSQL para
     * devolver cuatro numeros. Y porque sumar los resultados diarios daria
     * exactamente lo mismo solo si la agrupacion es por persona **y fecha**, que es
     * la condicion que hay que poder leer en un sitio en lugar de deducirla.
     *
     * ## Y por que aqui y no una consulta propia del cuadro
     *
     * Porque «jornada completa» tiene que significar **una sola cosa** en el
     * producto: la del `workdays_complete_ratio{site}` que pinta Grafana desde la
     * tarea 3.1 y la del indicador que enseña el panel. Con dos consultas, el
     * cuadro de mando y el cuadro de impacto acabarian dando dos porcentajes para
     * la misma semana, y el cuadro perderia justo la funcion que tiene (decision 8
     * de la ficha 3.13). **Lo que no comparten es la ventana**: Grafana mira los
     * ultimos siete dias de contadores y el cuadro un periodo civil cerrado.
     *
     * @param  string  $from  Primera jornada, inclusive, en `Y-m-d` y en la zona del centro.
     * @param  string  $to  Ultima jornada, inclusive.
     * @return array<int, array{complete: int, total: int}> Indexado por centro. Un centro sin
     *                                                      ninguna jornada en el rango **no
     *                                                      aparece**, igual que arriba.
     */
    public function completionBetween(string $from, string $to): array;
}
