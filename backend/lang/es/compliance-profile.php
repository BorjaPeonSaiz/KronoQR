<?php

declare(strict_types=1);

/*
 * Textos del perfil de cumplimiento (RF-PD-07, tarea 5.2).
 *
 * `attributes` son los nombres que ve una persona en un `422`; `errors` los
 * mensajes de las excepciones de dominio, que el borde resuelve con
 * `ProblemDetails::translated()` en el idioma negociado.
 */

return [

    'attributes' => [
        'name' => 'nombre del convenio',
        'min_rest_hours' => 'descanso mínimo entre jornadas',
        'max_daily_hours' => 'jornada diaria ordinaria',
        'max_weekly_hours' => 'jornada semanal ordinaria',
        'break_required_after_hours' => 'tramo continuo máximo sin pausa',
        'week_starts_on' => 'día en que empieza la semana',
        'holiday_calendar' => 'calendario de festivos',
        'retention_years' => 'años de conservación del registro',
    ],

    'strict_integer' => 'El campo :attribute tiene que ser un número entero, sin comillas.',

    'errors' => [
        'no_fields' => 'Indica al menos un campo del perfil que quieras cambiar.',
        'not_integer' => 'El campo «:field» espera un número entero y ha recibido :received.',
        'not_text' => 'El campo «:field» espera un texto y ha recibido :received.',
        'not_empty' => 'El campo «:field» no puede quedarse vacío.',
        'too_long' => 'El campo «:field» admite hasta :maximum caracteres y ha recibido :length.',
        'out_of_range' => 'El campo «:field» admite de :minimum a :maximum y ha recibido :value.',
        'not_date_list' => 'El calendario de festivos admite fechas en formato AAAA-MM-DD y ha recibido «:received».',
        'duplicated' => 'El campo «:field» no admite valores repetidos.',
        'weekly_below_daily' => 'La jornada semanal (:weekly h) no puede quedar por debajo de la diaria (:daily h).',
        'name_taken' => 'Ya hay otro perfil de cumplimiento llamado «:name». Elige un nombre distinto.',
    ],

];
