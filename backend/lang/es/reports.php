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

    /*
     * EL CUADRO DE IMPACTO Y ADOPCION (RF-IN-08, RNF-D-01, tarea 3.13).
     *
     * LOS CRITERIOS SON PARTE DEL CUADRO, NO DE LA DOCUMENTACION, por lo mismo
     * que en el informe por periodo: un porcentaje sin su definicion es un
     * numero que cada persona interpreta a su manera, y este cuadro se enseña en
     * reuniones donde se decide si el sistema se renueva.
     *
     * Cada linea se escribe para quien dirige el hotel, no para quien programo
     * esto: dice QUE se ha contado, no como se ha implementado. Y dice tambien
     * lo que el cuadro NO sabe, que es la mitad de su honestidad.
     */
    'adoption' => [

        'criteria' => [

            'workdays_complete' => 'Una jornada cuenta como registro completo cuando todos sus tramos están cerrados. El denominador son las jornadas con algún tramo: quien no fichó no entra ni arriba ni abajo, así que un día de cierre del hotel no baja el indicador. Los tramos anulados y las versiones sustituidas por una corrección no cuentan.',

            'origin' => 'El reparto por origen cuenta solo los fichajes aceptados. Un escaneo rechazado —tarjeta desconocida, firma inválida, rebote— no es un fichaje y no entra en el reparto, aunque sí cuenta como intento atendido en la disponibilidad.',

            'corrections' => 'Las correcciones se cuentan por la fecha en la que se hicieron, no por la jornada que corrigen, y se comparan con los fichajes aceptados del mismo periodo. Entran las tres clases: alta manual, rectificación y anulación. Así el cuadro de un mes cerrado no cambia cuando alguien rectifica un día antiguo.',

            'availability' => 'La disponibilidad del acto de fichar no es el tiempo de servicio del servidor: mide si la persona pudo fichar. Arriba van todos los fichajes atendidos, incluidos los rechazados por una regla —el sistema estaba ahí y contestó— y los que llegaron por la cola sin conexión. Abajo, además, los intentos que la tablet no pudo cursar y reportó como error: cámara no disponible o sin permiso, escáner que no arranca, lector que no carga, almacenamiento sin conexión inservible y envío fallido. Es una aproximación por exceso a favor de la fiabilidad: un intento que ni llegó a producir un error no se ve, y la limpieza periódica del histórico de errores recorta el denominador en los periodos antiguos.',

            'offline' => 'De los fichajes atendidos, los que llegaron con más de un minuto de retraso entre el momento real y su recepción: los que estuvieron esperando en la cola de la tablet. Es la parte de la disponibilidad que no dependió del servidor.',

            'incidents' => 'El tiempo hasta resolver se mide solo sobre los turnos sin cerrar que se resolvieron dentro del periodo, desde que se detectaron hasta que se cerraron. La mediana va al lado de la media a propósito: una sola incidencia olvidada tres semanas dispara la media y no la mediana. Las incidencias abiertas son la foto de hoy, de cualquier tipo, y por eso no se comparan con el periodo anterior.',

            'credentials' => 'Sin tarjeta entregada son las personas de alta que hoy no tienen ninguna credencial vigente ya entregada en mano. La tarjeta impresa y todavía en el cajón cuenta como no entregada. Es la foto de hoy y no se compara con el periodo anterior.',

            'hours' => 'Las horas trabajadas y contratadas son las del registro horario ya consolidado, de la instalación entera, sin desglose por persona ni por departamento. Los días con un turno todavía abierto no aportan minutos. Los días sin contrato vigente no suman horas contratadas.',

            'baseline' => 'Las horas al mes consolidando hojas de horas son un dato que declara el cliente: describen el trabajo manual anterior a la instalación del sistema, y ningún programa puede medir lo que se hacía antes de que existiera. Si no se ha declarado, la línea sale vacía en lugar de inventar una mejora.',

            'previous_period' => 'El periodo anterior es el mismo número de días inmediatamente antes del primero del periodo pedido. Cuando el periodo anterior no tiene con qué comparar, la variación sale vacía y no como cero: «no hubo actividad» y «hubo actividad y salió mal» no son lo mismo.',

            'timezone' => 'Todo se mide en la zona horaria del centro. Un turno de 22:00 a 06:00 cuenta entero en el día en que empezó y no se parte a medianoche, y las semanas con cambio de hora no distorsionan ningún porcentaje.',

            'aggregate' => 'El cuadro es de la instalación entera y no lleva ningún nombre ni ningún identificador de persona. No hay desglose por departamento a propósito: en un departamento de una persona, «horas trabajadas» sería su dato individual.',

            'dashboard' => 'Las definiciones son las mismas que usa el cuadro de mando técnico de la instalación, pero la ventana no: aquél mira los últimos días y éste un periodo cerrado, así que las dos cifras pueden diferir sin que ninguna esté mal.',
        ],

        /*
         * Rotulos del fichero exportado. Los tres formatos usan estos mismos
         * textos, por lo mismo que el informe por periodo: quien compare dos
         * descargas del mismo cuadro en formatos distintos tiene que ver lo
         * mismo.
         */
        'document' => [

            'title' => 'Cuadro de impacto y adopción',

            'period' => 'Periodo',

            'previous_period' => 'Periodo anterior',

            'time_zone' => 'Zona horaria del centro',

            'generated_at' => 'Generado el',

            'issuer' => 'Emitido por',

            'issuer_unknown' => 'Cuenta no identificable',

            'rows' => 'Indicadores',

            'digest' => 'Huella SHA-256 del contenido',

            'criteria' => 'Criterios de este cuadro',

            'sheet_indicators' => 'Indicadores',

            'sheet_criteria' => 'Criterios',

            'empty' => 'Sin datos',

            'origin_breakdown' => 'Reparto de fichajes por origen',
        ],

        /* Las columnas de la tabla de indicadores, en orden. */
        'columns' => [

            'indicator' => 'Indicador',

            'current' => 'Periodo',

            'previous' => 'Periodo anterior',

            'delta' => 'Variación',

            'target' => 'Objetivo',

            'status' => 'Estado',
        ],

        /* Las columnas del reparto por origen. */
        'origin_columns' => [

            'origin' => 'Origen',

            'scans' => 'Fichajes',

            'share' => 'Cuota',
        ],

        /*
         * Los doce indicadores. El rotulo dice QUE mide, no como se llama la
         * clave: quien lee el papel no sabe que existe `qr_scans_ratio`.
         */
        'indicators' => [

            'workdays_complete_ratio' => 'Jornadas con registro completo',

            'qr_scans_ratio' => 'Fichajes por tarjeta QR',

            'manual_corrections_ratio' => 'Correcciones manuales sobre fichajes',

            'clocking_availability_ratio' => 'Disponibilidad del acto de fichar',

            'offline_resolved_ratio' => 'De ellos, resueltos sin servidor',

            'incident_resolution_mean_minutes' => 'Tiempo medio hasta resolver un turno sin cerrar',

            'incident_resolution_median_minutes' => 'Tiempo mediano hasta resolver un turno sin cerrar',

            'open_incidents' => 'Incidencias abiertas hoy',

            'employees_without_credential' => 'Personas sin tarjeta entregada hoy',

            'worked_minutes' => 'Horas trabajadas',

            'contracted_minutes' => 'Horas contratadas',

            'baseline_manual_minutes_per_month' => 'Horas al mes consolidando hojas de horas (declarado)',
        ],

        /* Los cuatro origenes de fichaje. */
        'origins' => [

            'qr_kiosk' => 'Tarjeta QR en el quiosco',

            'pin_kiosk' => 'PIN en el quiosco',

            'manual_admin' => 'Corrección manual',

            'import' => 'Importación',
        ],

        /*
         * Como se enuncia el objetivo del §1.3 en el fichero. `reduction` no
         * dice «cumple» ni «no cumple» a proposito: el producto no puede medir
         * el trabajo anterior a su instalacion.
         */
        'target' => [

            'at_least' => 'al menos :value',

            'at_most' => 'menos de :value',

            'reduction' => 'reducir un :value % sobre la línea base',

            'none' => '—',
        ],

        /*
         * Si el indicador alcanza su objetivo. «Sin dato» no es «no cumple»: es
         * que no hay con qué decidirlo, y confundirlos es como un cuadro honesto
         * se convierte en un cuadro alarmista.
         */
        'status' => [

            'met' => 'Dentro del objetivo',

            'not_met' => 'Fuera del objetivo',

            'unknown' => 'Sin dato',

            'no_target' => 'Sin objetivo',
        ],
    ],
];
