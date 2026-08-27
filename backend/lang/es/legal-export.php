<?php

declare(strict_types=1);

/*
 * Textos de la exportacion legal para la Inspeccion de Trabajo (RF-IN-05,
 * RL-06, tarea 1.17).
 *
 * POR QUE ESTAN AQUI Y NO EN EL CODIGO. El idioma del documento es
 * configuracion de la instalacion (`APP_LOCALE`), no una constante del programa:
 * el producto se vende a clientes distintos y nada especifico de uno vive en el
 * repositorio (regla dura 13, ADR-017). Los identificadores del sistema siguen
 * en ingles (doc 02 §3.5); lo que se traduce es lo que lee una persona.
 *
 * QUE NO SE TRADUCE, Y ES DELIBERADO:
 *
 *   - `employee_code`, los UUID y los nombres propios: son datos, no texto.
 *   - Los nueve codigos de motivo del Anexo C del doc 01. Son un catalogo
 *     cerrado con valor de referencia: quien cruza este fichero con
 *     `shift_corrections` o con `audit_log` tiene que ver la misma cadena en los
 *     tres sitios. Traducirlos convertiria el codigo en una etiqueta y perderia
 *     esa correspondencia.
 */

return [

    'title' => 'Registro horario — exportacion normalizada para la Inspeccion de Trabajo',

    'header' => [
        'installation' => 'Instalacion',
        'generated_at' => 'Generado (UTC)',
        'period' => 'Periodo',
        'scope' => 'Alcance',
        'legal_basis' => 'Base legal',
        'criteria' => 'Criterios de inclusion',
    ],

    'period_value' => 'del :from al :to, por fecha de jornada',

    'scope' => [
        'everyone' => 'Todos los trabajadores con registro en el periodo',
        'employee' => 'Un unico trabajador (:uuid)',
    ],

    'legal_basis_value' => 'Registro diario de jornada. Art. 34.9 del Estatuto de los Trabajadores.',

    'criteria' => [
        'entries' => 'Una fila TRAMO por cada periodo de trabajo cuya jornada cae dentro del periodo exportado. Un turno de noche es un unico tramo, atribuido a la jornada en la que empezo.',
        'voided' => 'Los tramos anulados se incluyen, con su estado indicado, y no suman horas en el total de la jornada.',
        'superseded' => 'Las versiones sustituidas por una correccion no aparecen como tramo: lo que decian consta en la columna «Antes» de la correccion que las sustituyo. No se ha borrado nada.',
        'corrections' => 'Una fila CORRECCION por cada rectificacion del registro, con su autor, su momento y su motivo.',
        'times' => 'Las horas locales estan expresadas en la zona horaria del centro que indica cada fila. Se incluye ademas la marca en UTC, que es la almacenada.',
        'durations' => 'Las duraciones se expresan como HH:MM. Nunca en formato decimal.',
        'file' => 'Fichero CSV en UTF-8 con marca de orden de bytes, separado por punto y coma.',
    ],

    'columns' => [
        'record_type' => 'Tipo',
        'employee_code' => 'Codigo de empleado',
        'employee_name' => 'Trabajador',
        'employee_uuid' => 'Identificador de trabajador',
        'site' => 'Centro',
        'department' => 'Departamento',
        'timezone' => 'Zona horaria del centro',
        'work_date' => 'Jornada',
        'entry_number' => 'Tramo',
        'shift_entry_id' => 'Identificador de tramo',
        'local_in' => 'Entrada (hora local)',
        'local_out' => 'Salida (hora local)',
        'duration' => 'Duracion (HH:MM)',
        'day_total' => 'Total de la jornada (HH:MM)',
        'status' => 'Estado',
        'clock_in_source' => 'Origen de la entrada',
        'clock_out_source' => 'Origen de la salida',
        'utc_in' => 'Entrada (UTC)',
        'utc_out' => 'Salida (UTC)',
        'correction_local_at' => 'Correccion: momento (hora local)',
        'correction_utc_at' => 'Correccion: momento (UTC)',
        'correction_author' => 'Correccion: autor',
        'correction_author_id' => 'Correccion: identificador del autor',
        'correction_action' => 'Correccion: accion',
        'correction_reason' => 'Correccion: motivo',
        'correction_explanation' => 'Correccion: explicacion',
        'correction_before' => 'Correccion: antes',
        'correction_after' => 'Correccion: despues',
    ],

    'record_type' => [
        'shift_entry' => 'TRAMO',
        'correction' => 'CORRECCION',
    ],

    'status' => [
        'open' => 'Abierto',
        'closed' => 'Cerrado',
        'anomalous' => 'Pendiente de revision',
        'voided' => 'Anulado',
    ],

    'source' => [
        'qr_kiosk' => 'Escaneo de tarjeta en quiosco',
        'pin_kiosk' => 'PIN en quiosco',
        'manual_admin' => 'Registro manual de gestion',
        'import' => 'Importacion',
    ],

    'action' => [
        'created' => 'Alta de tramo',
        'modified' => 'Cambio de horas',
        'closed' => 'Cierre de tramo',
        'voided' => 'Anulacion de tramo',
    ],

];
