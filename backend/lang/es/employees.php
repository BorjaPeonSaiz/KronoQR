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

        'apply_now_requires_due_termination' => '«Aplicar ya» solo vale con una fecha de cese de hoy o anterior, y el '
            .'último día (:terminated_on) aún no ha llegado (hoy es :today). Quítalo para programar la baja, o pon la '
            .'fecha de hoy.',

        'offboarding_not_scheduled' => 'Esta persona no tiene ninguna baja programada.',

        'offboarding_due' => 'La fecha de cese (:terminated_on) ya ha pasado (hoy es :today). La baja ya rige y no se '
            .'puede anular.',

        'record_minimised' => 'La ficha de esta persona se minimizó el :anonymized_on al vencer su plazo de '
            .'conservación: ya no admite contratos ni ausencias.',
    ],

];
