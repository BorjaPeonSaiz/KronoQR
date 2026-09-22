<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Workforce\Domain\ValueObject\AbsenceType;

/**
 * El tipo `other` se ha registrado sin nota (**RF-GP-04**).
 *
 * `other` existe para el permiso que no es vacaciones, ni baja, ni permiso
 * tipificado. Sin texto no describe nada, y una categoria que no describe nada
 * acaba usandose para todo: en seis meses la mitad del cuadro seria `other` y el
 * informe de absentismo no diria nada sobre lo que estaba pasando en el hotel.
 *
 * `422` y no `409`: hay un campo que rellenar en el formulario. Quien lo recibe
 * no necesita releer nada.
 *
 * **Se nombra el tipo y no la nota**, y aqui si es aceptable: `other` no es dato
 * de salud —lo problematico es `sick_leave`— y sin el, el mensaje no diria por
 * que hace falta el texto. La nota en si no aparece nunca (regla dura 21).
 */
final class AbsenceRequiresNote extends WorkforceDomainException
{
    public static function forType(AbsenceType $type): self
    {
        return new self('El tipo de ausencia «'.$type->value.'» necesita una nota que lo explique.');
    }
}
