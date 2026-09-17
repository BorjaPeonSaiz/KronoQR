<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Un tramo **cerrado y vigente** de una jornada, con lo justo para medir RN-12.
 *
 * Solo llega aqui el mas largo de la jornada: es el unico que puede superar el
 * maximo continuo, asi que traer los demas seria cargar la plantilla entera para
 * mirar una fila por dia.
 *
 * **La duracion se calcula restando instantes UTC** y no se lee de
 * `duration_minutes`. No es desconfianza de la columna: es que el mismo objeto
 * mide despues huecos entre jornadas, donde no hay ninguna columna que leer, y
 * dos aritmeticas distintas para la misma unidad son dos sitios donde el cambio
 * de hora puede colarse (RN-09).
 */
final readonly class ComplianceShiftSegment
{
    public function __construct(
        /** Identificador publico del tramo, para que la pantalla lo señale. */
        public string $uuid,
        public DateTimeImmutable $clockedInAt,
        public DateTimeImmutable $clockedOutAt,
    ) {}

    /**
     * Minutos enteros entre las dos marcas, truncando los segundos.
     *
     * Trunca hacia abajo, como el resto del producto: 6 h 0 min 59 s son 360
     * minutos y no 361, asi que no alerta. Redondear al alza convertiria el
     * limite abierto del doc 01 en cerrado por accidente.
     */
    public function minutes(): int
    {
        return intdiv($this->clockedOutAt->getTimestamp() - $this->clockedInAt->getTimestamp(), 60);
    }
}
