<?php

declare(strict_types=1);

/*
 * Informes por periodo (RF-IN-01, RF-IN-02, RF-IN-03, tarea 2.8).
 *
 * LOS CRITERIOS DE INCLUSION SON PARTE DEL INFORME, NO DE LA DOCUMENTACION.
 * `/informe-nuevo` lo exige por escrito: un informe de horas sin ellos es una
 * tabla de numeros que cada persona interpreta a su manera —¿cuenta el turno
 * que sigue abierto? ¿y el tramo que se anulo?— y esa interpretacion acaba
 * discutiendose en una reunion de nomina, no en el codigo.
 *
 * Las claves las decide `GeneratePeriodReport`, que las transporta SIN traducir
 * porque el dominio no tiene idioma. La traduccion es del `Resource`, y la
 * misma lista la escribira la tarea 2.9 en la cabecera del CSV y del PDF.
 *
 * Cada linea se escribe para alguien de RRHH, no para quien programo esto:
 * dice QUE se ha contado, no como se ha implementado.
 */

return [

    'criteria' => [

        'source' => 'Los totales salen del registro horario ya consolidado (proyección de jornadas), no se recalculan para este informe.',

        'work_date' => 'Cada turno se atribuye entero a la jornada en la que empezó, en la zona horaria del centro: un turno de 22:00 a 06:00 cuenta en el día de entrada y no se parte a medianoche.',

        'voided' => 'No se cuentan los tramos anulados ni las versiones sustituidas por una corrección: solo la versión vigente de cada tramo.',

        'incidents' => 'Una incidencia sin resolver no descuenta horas. Los días con incidencia se cuentan aparte, en la columna correspondiente, para que se revisen.',

        'empty_days' => 'Los días sin actividad aparecen con cero y no se omiten. En los agregados por departamento o centro, los contadores de días son días-persona.',

        'contracted' => 'Las horas contratadas del periodo se prorratean por día natural de vigencia del contrato: días de vigencia × horas semanales ÷ 7. Los días sin contrato vigente no suman y se informan aparte.',

        'scope' => 'El informe solo incluye a las personas que están dentro del alcance de quien lo pide, incluidas las que causaron baja durante el periodo.',

        'open_shifts_excluded' => 'Los días con un turno todavía abierto no aportan minutos, porque la jornada aún no ha terminado. Cuentan como día con actividad y se indican aparte.',

        'open_shifts_included' => 'Los días con un turno todavía abierto aportan los minutos que ya tienen cerrados; el turno en curso no suma nada hasta que se cierre.',

        'iso_week' => 'Las semanas empiezan en lunes (semana ISO 8601) y se recortan al rango pedido.',
    ],

    /*
     * Rotulos del fichero exportado (RF-IN-04, tarea 2.9).
     *
     * LOS TRES FORMATOS USAN ESTOS MISMOS TEXTOS. CSV, XLSX y PDF recorren la
     * misma lista de columnas y escriben el mismo bloque de cabecera, de modo que
     * quien compare dos descargas del mismo informe en formatos distintos vea lo
     * mismo. Si cada escritor tuviera sus rotulos, la comparacion tendria que
     * hacerse de memoria.
     */

    'document' => [

        'title' => 'Informe de horas por periodo',

        'period' => 'Periodo',

        'granularity' => 'Granularidad',

        'group_by' => 'Agrupado por',

        'time_zone' => 'Zona horaria del centro',

        'generated_at' => 'Generado el',

        'issuer' => 'Emitido por',

        'issuer_unknown' => 'Cuenta no identificable',

        'rows' => 'Filas',

        'digest' => 'Huella SHA-256 del contenido',

        'criteria' => 'Criterios de este informe',

        'empty' => 'No hay ninguna fila en este periodo dentro del alcance de quien pidió el informe.',

        'contract_coverage' => 'Hay :days días-persona del periodo sin contrato registrado, que afectan a :employees persona(s). Esos días no suman horas contratadas: la desviación de esas filas está incompleta.',

        'sheet_hours' => 'Horas',

        'sheet_criteria' => 'Criterios',
    ],

    /*
     * Sobre que se agregan las horas de cada fila. Las claves son las de
     * `ReportGrouping`, que es tambien lo que dice `subject.kind` en la respuesta
     * JSON: un rotulo distinto por formato haria que la misma fila se llamara de
     * dos maneras.
     */
    'subject_kind' => [

        'employee' => 'Empleado',

        'department' => 'Departamento',

        'site' => 'Centro',
    ],

    'subject' => [

        /*
         * El cubo de quien no tiene departamento. NO se inventa desde el
         * servidor en la respuesta JSON —alli viaja nulo y lo traduce el
         * cliente—, pero un fichero no tiene cliente que lo traduzca: una celda
         * vacia en la columna del sujeto se lee como un error de la exportacion.
         */
        'unassigned' => 'Sin departamento',
    ],

    /* Las mismas cuatro de `ReportGranularity`, para el bloque de cabecera. */
    'granularity' => [

        'day' => 'Día',

        'week' => 'Semana',

        'month' => 'Mes',

        'range' => 'Todo el periodo',
    ],

    /*
     * Las columnas de la tabla, en el orden de `PeriodReportLayout::COLUMNS`.
     *
     * NO HAY NINGUNA COLUMNA DE MINUTOS, y es deliberado: las duraciones van en
     * `HH:MM` y nada mas. Una columna de minutos al lado invitaria a dividir
     * entre 60 y volver a la hora decimal que este informe prohibe.
     */
    'columns' => [

        'subject_kind' => 'Tipo',

        'subject' => 'Sujeto',

        'employee_code' => 'Código de empleado',

        'employee_uuid' => 'Identificador',

        'department_id' => 'Departamento (id)',

        'period_from' => 'Desde',

        'period_to' => 'Hasta',

        'worked' => 'Trabajado',

        'contracted' => 'Contratado',

        'deviation' => 'Desviación',

        'overtime' => 'Exceso',

        'shift_count' => 'Tramos',

        'days_in_period' => 'Días',

        'days_with_activity' => 'Días con actividad',

        'days_without_activity' => 'Días sin actividad',

        'open_shift_days' => 'Días con turno abierto',

        'incident_days' => 'Días con incidencia',

        'days_without_contract' => 'Días sin contrato',
    ],
];
