<?php

declare(strict_types=1);

/*
 * Textos del registro horario que ve una persona del panel (F1).
 *
 * Solo los del alta y la corrección manuales: el quiosco no recibe nunca el
 * motivo de un rechazo (regla dura 17) y estos mensajes no pasan por él.
 */

return [

    'errors' => [
        'mark_in_future' => 'Esa hora todavía no ha llegado: no se pueden registrar horas futuras. '
            .'Se admite un margen de :minutes minuto(s) sobre la hora del servidor.',
        'work_date_in_future' => 'Esa jornada todavía no ha empezado: no se pueden registrar horas de un día futuro.',
        'work_date_outside_employment' => 'Esta persona está de baja desde el :terminated_on: solo se le pueden '
            .'registrar horas de jornadas entre su alta (:hired_on) y su cese (:terminated_on).',
        'entry_does_not_continue_work_day' => 'Esas horas no son de la jornada del :work_date: el tramo empieza otro '
            .'día y entre la salida anterior y su entrada median :minutes minutos o más, el descanso mínimo entre '
            .'jornadas. Dalas de alta en la jornada en que empiezan.',
        'mark_after_termination' => 'Esta persona cesó el :terminated_on: esa hora queda fuera de su última jornada. '
            .'Solo se admiten horas que continúen la jornada del día de cese.',
    ],

];
