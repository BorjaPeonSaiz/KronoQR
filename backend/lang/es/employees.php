<?php

declare(strict_types=1);

/*
 * Textos de la ficha del empleado que ve una persona del panel (RF-GP-03).
 *
 * Los lee quien está dando de baja a alguien, normalmente con la persona
 * delante o recién salida de su último turno. Por eso cada mensaje dice qué
 * fecha se admite y no solo cuál se ha rechazado.
 *
 * NINGUNO NOMBRA A LA PERSONA: solo fechas. El mensaje técnico de la excepción,
 * que es el que va al log, tampoco (regla dura 21).
 */

return [

    'errors' => [

        'termination_before_hiring' => 'La fecha de cese (:terminated_on) es anterior a la de alta (:hired_on).',

        'termination_after_today' => 'La fecha de cese (:terminated_on) es posterior a hoy (:today). La baja es '
            .'efectiva al registrarla; regístrala el último día, cuando haya terminado su turno.',

        'not_started_termination_must_be_hire_date' => 'Esta persona aún no ha empezado a trabajar (alta el '
            .':hired_on). La única fecha de cese posible es la de alta, :hired_on.',
    ],

];
