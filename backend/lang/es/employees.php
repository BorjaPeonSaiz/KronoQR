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

        'offboarding_due_reschedule' => 'La fecha de cese (:terminated_on) ya ha pasado (hoy es :today) y ya no se '
            .'puede cambiar. Con esa misma fecha, la baja se aplica en el acto.',

        'record_minimised' => 'La ficha de esta persona se minimizó el :anonymized_on al vencer su plazo de '
            .'conservación: ya no admite contratos, ausencias, readmisión ni corrección de su fecha de cese. Quien '
            .'vuelve se da de alta como persona nueva.',
        'employee_not_terminated' => 'Esta persona no está de baja y no se puede dar de alta de nuevo. Si tiene una '
            .'baja programada, anúlala o espera a que se aplique.',

        'offboarding_not_effective' => 'Este periodo no tiene la baja efectiva. Una baja programada se cambia de fecha '
            .'desde la baja.',

        'rehire_not_after_termination' => 'La fecha de alta (:hired_on) tiene que ser posterior a la fecha de cese '
            .'(:terminated_on).',

        'rehire_not_found' => 'Esta persona no existe.',

        'correction_after_today' => 'La fecha de cese (:terminated_on) no puede ser posterior a hoy (:today).',

        'correction_before_hiring' => 'La fecha de cese (:terminated_on) no puede ser anterior a la fecha de alta del '
            .'periodo (:hired_on).',

        'correction_reaches_next_period' => 'La fecha de cese (:terminated_on) tiene que ser anterior a la fecha de '
            .'alta del periodo siguiente (:next_hired_on).',

        'correction_unchanged' => 'La fecha de cese ya es :terminated_on.',

    ],

];
