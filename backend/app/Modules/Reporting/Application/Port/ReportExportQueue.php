<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * Quien pone la generacion del informe en la cola (**RF-IN-06**, decision 4 de
 * la ficha 3.9).
 *
 * ## Por que el informe grande es asincrono
 *
 * Porque el sincrono ya dice que no cabe: `GET /reports/period` responde `422`
 * por encima de `reporting.period.max_range_days` o de `max_rows`, y ese `422`
 * remite aqui. Lo que en diferido desaparece son esos dos techos —no el trabajo:
 * la consulta sigue costando lo mismo— y lo que se gana es que nadie espera con
 * una pestaña abierta a que termine, ni la peticion muere en los 60 s de
 * `fastcgi_read_timeout`.
 *
 * ## El puerto recibe un `uuid` y nada mas
 *
 * Ni el modelo, ni los parametros, ni el alcance: un trabajo en cola se
 * **serializa** —en Redis, donde se queda hasta que alguien lo recoge— y meter
 * ahi el alcance de una persona o el filtro por empleado los duplicaria en un
 * sitio que nadie audita (regla dura 21). Con el `uuid`, el trabajador relee la
 * fila al arrancar y ve el estado real: eso es tambien lo que hace que un
 * reintento no rehaga un informe que ya termino.
 */
interface ReportExportQueue
{
    public function enqueue(string $uuid): void;
}
