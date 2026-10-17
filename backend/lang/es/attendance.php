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
        'entry_does_not_continue_work_day' => 'Las horas de este tramo son de otra jornada: empieza otro día y no '
            .'continúa la jornada del :work_date, porque entre la salida anterior y su entrada median :hours h o más '
            .'(el descanso mínimo entre jornadas) o hay un tramo de otra jornada. Dalo de alta en la jornada en que '
            .'empieza.',
        'next_entry_would_not_continue_work_day' => 'El tramo siguiente dejaría de continuar la jornada del :work_date: '
            .'empieza otro día y su entrada quedaría a :hours h o más de la salida anterior (el descanso mínimo entre '
            .'jornadas) o detrás de un tramo de otra jornada. Corrige o anula antes ese tramo.',
        'void_would_change_work_date' => 'Este tramo abre la jornada del :work_date y el siguiente empieza otro día: '
            .'anularlo dejaría sus horas en otra jornada. Anula antes los tramos que vienen detrás.',
        'mark_after_termination' => 'Esta persona cesó el :terminated_on: esa hora queda fuera de su última jornada. '
            .'Solo se admiten horas que continúen la jornada del día de cese.',
    ],

];
