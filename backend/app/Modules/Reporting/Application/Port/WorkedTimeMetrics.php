<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * La metrica de negocio del tiempo trabajado: `worked_minutes_total{site,
 * department}` (doc 02 §8.2, doc 01 §9.2).
 *
 * ## Que responde y por que es un contador
 *
 * Alimenta el cuadro de Negocio del §8.3 y el indicador «Horas trabajadas frente
 * a contratadas por departamento» del doc 01 §9.2. Es un **contador**: solo
 * crece, y lo que se mira es su derivada —`rate()`— o su incremento en una
 * ventana, no su valor absoluto.
 *
 * ## Se incrementa al CERRAR un tramo, no al leer el informe
 *
 * Esta es la decision que hay que dejar escrita. Un contador solo puede crecer,
 * asi que hay exactamente un momento en el que se puede incrementar sin mentir:
 * cuando el hecho **ocurre**, es decir, cuando un tramo se cierra y su duracion
 * queda calculada (RF-AT-03).
 *
 * Las dos alternativas se descartan por la misma razon:
 *
 *   - **Al leer el informe**: la serie subiria cada vez que alguien abre la
 *     pantalla y contaria las mismas horas tantas veces como se consulten. No
 *     hay forma de corregir un contador a la baja.
 *   - **Al recalcular `daily_totals`**: la proyeccion se **recalcula** entera en
 *     cada cambio (RN-06, regla dura 7), asi que sumar su resultado contaria el
 *     dia completo otra vez en cada correccion.
 *
 * La consecuencia asumida es que **las correcciones posteriores no se reflejan**
 * en esta serie: si un tramo se anula, los minutos ya contados siguen contados.
 * Es aceptable porque esto es un termometro de negocio y no el registro legal
 * —ese es `daily_totals`, y es el que responde a una nomina y a una inspeccion—,
 * y porque la alternativa seria un `gauge` que hay que recomputar entero cada
 * vez. Queda dicho para que nadie use esta metrica para cuadrar horas.
 *
 * ## Las etiquetas
 *
 * `site` y `department`, las del §8.2. **Ningun `employee_uuid`**: una serie
 * temporal por persona seria un registro de presencia paralelo, sin retencion ni
 * control de acceso (regla dura 21, RGPD, minimizacion). La cardinalidad es la de
 * los departamentos del hotel: unidades o decenas.
 */
interface WorkedTimeMetrics
{
    /**
     * Suma los minutos de un tramo que se acaba de cerrar.
     *
     * @param  int  $siteId  Centro del empleado.
     * @param  string  $department  Nombre del departamento, o cadena vacia si no tiene.
     *                              El cubo vacio tiene que existir: sin el, la suma de los
     *                              departamentos no cuadraria con el total del centro.
     * @param  int  $minutes  Duracion del tramo cerrado. Nunca negativa.
     */
    public function workedMinutes(int $siteId, string $department, int $minutes): void;
}
