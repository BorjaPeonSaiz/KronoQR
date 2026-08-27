<?php

declare(strict_types=1);

/*
 * Textos del historico personal que el empleado se descarga desde su portal
 * (RF-ID-05, RL-05, tarea 1.11).
 *
 * POR QUE ESTAN AQUI Y NO EN EL CODIGO. El idioma del documento es configuracion
 * de la instalacion (`APP_LOCALE`), no una constante del programa: el producto se
 * vende a clientes distintos y nada especifico de uno vive en el repositorio
 * (regla dura 13, ADR-017). Los identificadores del sistema siguen en ingles
 * (doc 02 §3.5); lo que se traduce es lo que lee una persona.
 *
 * QUE NO SE TRADUCE, Y ES DELIBERADO, igual que en la exportacion legal:
 *
 *   - Los prefijos de fila `TRAMO` y `CORRECCION`, y los nueve codigos de motivo
 *     del Anexo C del doc 01. Son un catalogo cerrado con valor de referencia:
 *     quien cruce este fichero con la exportacion legal o con `shift_corrections`
 *     tiene que ver la misma cadena en los tres sitios.
 *   - El origen de cada marca (`qr_kiosk`, `pin_kiosk`, `manual_admin`...). Es un
 *     valor de columna, no una etiqueta.
 *   - Las fechas, las horas y los nombres propios: son datos.
 */

return [

    'title' => 'Mi registro horario',

    'header' => [
        'period' => 'Periodo',
        'time_zone' => 'Zona horaria del centro',
        'legal_basis' => 'Base legal',
        'criteria' => 'Criterios de inclusion',
    ],

    'period_value' => 'del :from al :to, por fecha de jornada',

    'legal_basis_value' => 'Registro diario de jornada. Art. 34.9 del Estatuto de los Trabajadores.',

    'criteria' => [
        'entries' => 'Una fila TRAMO por cada periodo de trabajo cuya jornada cae dentro del periodo exportado.',
        'night' => 'Un turno de noche es un unico tramo, atribuido a la jornada en la que empezo. No se parte a medianoche.',
        'open' => 'Un turno todavia abierto aparece sin hora de salida y aporta cero minutos al total del dia.',
        'corrections' => 'Una fila CORRECCION por cada rectificacion del registro, con su autor, su momento y su motivo. Nada se ha borrado.',
        'times' => 'Las horas locales estan expresadas en la zona horaria del centro que indica cada fila. Se incluye ademas la marca en UTC, que es la almacenada.',
        'durations' => 'Las duraciones se expresan como HH:MM. Nunca en formato decimal.',
        'file' => 'Fichero CSV en UTF-8 con marca de orden de bytes, separado por punto y coma.',
    ],

    'columns' => [
        'record_type' => 'Tipo',
        'work_date' => 'Jornada',
        'time_zone' => 'Zona horaria',
        'local_in' => 'Entrada (hora local)',
        'local_out' => 'Salida (hora local)',
        'duration' => 'Duracion',
        'day_total' => 'Total del dia',
        'status' => 'Estado',
        'clock_in_source' => 'Origen de la entrada',
        'clock_out_source' => 'Origen de la salida',
        'utc_in' => 'Entrada (UTC)',
        'utc_out' => 'Salida (UTC)',
        'correction_local_at' => 'Correccion (hora local)',
        'correction_author' => 'Corregido por',
        'correction_action' => 'Accion',
        'correction_reason' => 'Motivo',
        'correction_explanation' => 'Explicacion',
    ],

    /*
     * Los tres estados vigentes de un tramo, mas la jornada sin tramos.
     *
     * `anomalous` NO se traduce como «error»: el tramo esta cerrado con sus
     * marcas reales y lo que dice es que alguien tiene que mirarlo (RN-07,
     * RN-08). Llamarlo error haria que una persona diera por malas unas horas que
     * son las suyas.
     */
    'status' => [
        'open' => 'Turno abierto',
        'closed' => 'Cerrado',
        'anomalous' => 'Pendiente de revision',
        'none' => 'Sin tramos registrados',
    ],

];
