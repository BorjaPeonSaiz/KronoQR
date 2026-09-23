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

        /*
         * Las tres de RF-GP-04 (tarea 3.10, decision 7). SE LEEN COMO UN SOLO
         * PARRAFO y por eso van seguidas: las dos primeras dicen que se ha
         * descontado del absentismo y la tercera dice lo que NO se ha podido
         * descontar. Sacar la tercera de aqui dejaria «absentismo no justificado»
         * pareciendo una cifra de faltas al trabajo, que no es.
         */

        'absences' => 'Las ausencias registradas (vacaciones, baja médica, permiso) cuentan como días justificados y no como absentismo: se muestran en su propia columna. Si una ausencia coincide con un festivo, el día cuenta como ausencia y no se cuenta dos veces.',

        'holidays' => 'Hay :count día(s) festivo(s) del perfil de cumplimiento «:profile» dentro del periodo. No cuentan como absentismo y se muestran en su propia columna.',

        'no_roster' => 'El producto no conoce el cuadrante de turnos: no sabe qué días le tocaba trabajar a cada persona. Por eso los días de descanso semanal se cuentan como absentismo no justificado. Contraste esa columna con el calendario de turnos antes de usarla.',
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

        /* RF-GP-04. Ver el bloque `criteria` de arriba para el porqué de cada una. */

        'absence_days' => 'Días de ausencia',

        'holiday_days' => 'Festivos',

        'unjustified_absence_days' => 'Absentismo no justificado',
    ],

    /*
     * LA SALIDA A NOMINA (RF-IN-07, tarea 3.9).
     *
     * Rótulos POR OMISIÓN de las columnas del fichero de nómina. Se usan solo
     * cuando el cliente no ha escrito el suyo en `PAYROLL_EXPORT_COLUMNS`
     * (`id=Etiqueta`): ese rótulo configurado gana siempre y NO se traduce,
     * porque está escrito para encajar con la plantilla de importación de su
     * programa de nómina y traducirlo la rompería al cambiar el idioma del panel.
     *
     * SON DISTINTOS DE LOS DE `columns` DE ARRIBA aunque varias midan lo mismo, y
     * es deliberado: aquellos rotulan un informe que lee una persona y estos, un
     * fichero que carga un programa. Aquí importa que la cabecera diga
     * inequívocamente de qué columna se trata —«Horas trabajadas» y no
     * «Trabajado»—, porque quien la mapea en el importador no tiene el resto del
     * informe delante.
     *
     * El idioma es el de la INSTALACIÓN, no el del navegador (regla dura 13): la
     * ruta lleva `locale.installation`.
     */
    'payroll' => [

        'columns' => [

            'employee_code' => 'Código de empleado',

            'employee_uuid' => 'Identificador',

            'last_name' => 'Apellidos',

            'first_name' => 'Nombre',

            'full_name' => 'Nombre completo',

            'department' => 'Departamento',

            'period_from' => 'Desde',

            'period_to' => 'Hasta',

            'days_in_period' => 'Días del periodo',

            'days_with_activity' => 'Días con actividad',

            'shift_count' => 'Tramos',

            'worked_hours' => 'Horas trabajadas',

            'contracted_hours' => 'Horas contratadas',

            'deviation_hours' => 'Desviación',

            'overtime_hours' => 'Exceso de jornada',

            'absence_days' => 'Días de ausencia',

            'holiday_days' => 'Festivos',

            'unjustified_absence_days' => 'Absentismo no justificado',

            'days_without_contract' => 'Días sin contrato',

            'time_zone' => 'Zona horaria',
        ],
    ],

    /*
     * EL RESUMEN SEMANAL POR CORREO (RF-PR-05, tarea 3.12).
     *
     * Se escribe para el responsable de un departamento de hotel, no para quien
     * programo esto: dice que trabajo su gente la semana pasada y que tenia
     * contratado, y nada mas.
     *
     * TRES COSAS QUE ESTE TEXTO NO HACE, Y NO SON ESTILO:
     *
     *   · No compara ni ordena a nadie. No hay «el que mas», ni «por debajo de
     *     la media», ni un ranking: este producto registra jornada, no valora el
     *     trabajo de nadie (doc 01 §12).
     *   · No llama «horas extra» a la desviacion positiva. Que una hora sea
     *     extraordinaria lo decide el convenio, con compensaciones y periodos de
     *     referencia que el producto no modela.
     *   · No pide corregir nada. Detectar no corrige (RN-08) y una correccion la
     *     hace una persona, con su motivo, en el panel.
     *
     * Las duraciones llegan ya como `HH:MM` (`ReportedDuration`): nunca
     * decimales, que se leen mal y dependen de la configuracion regional.
     */
    'weekly_summary' => [

        'subject' => 'KronoQR · resumen de la semana del :from al :to',

        'greeting' => 'Hola:',

        'intro' => 'Este es el resumen de la semana :week (del :from al :to) de :departments.',

        'people' => 'Personas en tu ámbito esta semana: :count.',

        'line' => ':employee · trabajadas :worked de :contracted contratadas (:deviation) · :days día(s) con actividad · :absences de ausencia · :holidays festivo(s)',

        'more' => 'Y :count persona(s) más, que puedes ver en el panel.',

        'totals' => 'Total del ámbito: trabajadas :worked de :contracted contratadas (:deviation).',

        'incidents' => 'Tienes :count incidencia(s) sin resolver en la bandeja.',

        'incidents_none' => 'No tienes ninguna incidencia sin resolver.',

        'action' => 'Puedes verlo con el detalle día a día en el panel, en «Informes», eligiendo del :from al :to.',

        'not_a_ranking' => 'Las cifras salen del registro horario ya consolidado. El resumen no ordena ni compara a nadie, y la desviación no es una cantidad de horas extraordinarias: eso lo determina el convenio.',

        'footer' => 'Recibes este correo cada lunes porque tu instalación tiene activado el resumen semanal. Se envía una sola vez por semana.',

        'no_department' => 'tu ámbito',
    ],
];
