<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;

/**
 * De donde sale el registro horario ya escrito, para leerlo (RF-PA-03).
 *
 * ## Es un puerto de SOLO LECTURA, y no por convenio
 *
 * No hay aqui ningun metodo que escriba, y no puede haberlo: `Reporting` es el
 * lado de lectura (doc 02 §1.6, *«proyecciones y consultas de lectura»*). El
 * registro lo escriben el fichaje y las correcciones, en `Attendance`, con su
 * agregado, su transaccion y su asiento de auditoria. Un metodo de escritura en
 * este puerto seria un segundo camino hacia `shift_entries` que no pasa por
 * ninguna de las tres cosas.
 *
 * ## Por que existe el puerto si solo tiene un adaptador
 *
 * Por lo mismo que `EmployeeQueries` en `Workforce`: es el unico sitio donde
 * entrara el alcance por departamento de RF-ID-03 (tarea 2.1) y donde se decide
 * como se lee esta consulta. Si el controlador hablara con Eloquent, ese filtro
 * habria que repetirlo en cada camino de lectura y bastaria olvidarlo en uno.
 *
 * Y porque la consulta de verdad es SQL —tres tablas, dos `LEFT JOIN` y un
 * filtro por estado vigente— y esa consulta no puede vivir en `Application`: ahi
 * no hay Eloquent ni conexion. El adaptador esta en
 * `Reporting/Infrastructure/Persistence/`.
 *
 * ## Habla en tipos propios y en escalares
 *
 * ADR-025, restriccion 2: ni Laravel, ni tipos de otro modulo. Lo que sale es
 * {@see WorkDayJournal}, un objeto de valor de este modulo, nunca un modelo
 * Eloquent ni una fila suelta.
 */
interface WorkDayJournalReader
{
    /**
     * Zona horaria del centro al que esta adscrito el empleado, o `null` si el
     * empleado no existe.
     *
     * Se pregunta **antes** de leer el registro porque el rango por omision es
     * «los ultimos 31 dias» y que dia es hoy solo lo decide la zona del centro,
     * no la del servidor ni la del navegador (RN-04). El `null` es tambien lo
     * que distingue un `404` de una respuesta vacia: un empleado que no existe
     * no es lo mismo que un empleado que no trabajo esos dias.
     *
     * **Incluye a quien esta de baja**: dar de baja no borra la ficha (regla
     * dura 5) y su registro horario sigue teniendo que poder consultarse — es
     * justo lo que una inspeccion pide.
     */
    public function timeZoneOf(string $employeeUuid): ?string;

    /**
     * Las jornadas del rango, con sus tramos vigentes y su historico completo
     * de correcciones.
     *
     * Devuelve solo las jornadas **con actividad registrada**: un dia libre no
     * es una fila vacia, es la ausencia de fila. Y devuelve tambien las jornadas
     * cuyos tramos se anularon todos, con la lista vacia y su historico intacto
     * (regla dura 5).
     *
     * @param  string  $timeZone  Zona del centro actual del empleado, la que
     *                            devolvio {@see timeZoneOf()}.
     */
    public function journalFor(string $employeeUuid, string $timeZone, DateRange $range): WorkDayJournal;
}
