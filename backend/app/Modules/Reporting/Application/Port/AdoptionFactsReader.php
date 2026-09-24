<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\ValueObject\AdoptionFacts;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReportQuery;

/**
 * Los **hechos** del cuadro de impacto y adopcion, para dos periodos a la vez
 * (**RF-IN-08**).
 *
 * ## Devuelve recuentos, no indicadores
 *
 * Ni un porcentaje, ni una media ponderada, ni un `CASE WHEN` que decida si algo
 * cumple un objetivo. Lo que sale de aqui son enteros, y la aritmetica la hace
 * `Reporting\Domain\Policy\AdoptionIndicators` —nombrado en prosa porque un `use`
 * de `Domain` desde un puerto de `Application` esta bien, pero el argumento cabe
 * mejor aqui—: es lo que permite verificar «doce de trece jornadas son 92,31 %» en
 * una prueba unitaria de cinco lineas en lugar de sembrando trece jornadas en
 * PostgreSQL (decision 1 de la ficha 3.13).
 *
 * ## Los dos periodos en la misma llamada, y no dos llamadas
 *
 * Porque las consultas que los resuelven son las mismas con otro rango, y porque
 * dos llamadas separadas pueden ver dos estados de la base de datos: entre la
 * primera y la segunda cabe un fichaje, una correccion o la resolucion de una
 * incidencia, y el cuadro publicaria una comparacion entre dos fotos tomadas en
 * momentos distintos. Con una sola llamada, el adaptador puede resolver los dos
 * rangos en la misma pasada.
 *
 * ## Lo que NO devuelve: las horas
 *
 * `workedMinutes` y `contractedMinutes` de los dos periodos los pone el caso de
 * uso a partir de `GeneratePeriodReport`, que es quien sabe prorratear lo
 * contratado por dia de vigencia (RF-IN-03) y descontar festivos y ausencias
 * (RF-GP-04). Una segunda SQL que sumara horas seria una segunda verdad sobre las
 * mismas horas, y la que se creeria seria la equivocada (regla dura 7).
 *
 * ## Sin `employee_uuid`, sin nombres y sin departamentos
 *
 * Lo que atraviesa este puerto son recuentos de la instalacion entera. La pregunta
 * «¿quien se deja el turno abierto?» tiene su sitio —la bandeja de incidencias,
 * con control de acceso y retencion— y no es este cuadro (regla dura 21).
 */
interface AdoptionFactsReader
{
    /**
     * ## Aqui no entra «hoy», y es deliberado
     *
     * Las dos fotos que devuelve —incidencias abiertas y personas sin tarjeta
     * entregada— son preguntas sobre el **estado actual**, no sobre un dia: no hay
     * ninguna fecha que pasarles, y una que se pasara sin usarse acabaria
     * pareciendo un filtro que no existe. Los dos periodos si llevan fechas, y
     * vienen dentro de la consulta, resueltas por quien llama a partir del puerto
     * `Clock` (regla dura 2).
     *
     * @param  string  $timeZone  Zona IANA del centro (ADR-040): las marcas de tiempo se
     *                            comparan con `AT TIME ZONE` de esta zona, nunca en UTC en
     *                            crudo, porque en que dia civil cae un fichaje de las 23:40
     *                            lo decide el hotel y no el servidor (reglas duras 3 y 4).
     */
    public function factsFor(AdoptionReportQuery $query, string $timeZone): AdoptionFacts;
}
