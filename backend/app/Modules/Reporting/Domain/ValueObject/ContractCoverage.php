<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Cuanto del informe se pudo comparar contra un contrato, y cuanto no
 * (**RF-IN-03**).
 *
 * ## Por que va en `meta` y no se esconde
 *
 * La comparativa de trabajadas frente a contratadas solo significa algo si hay
 * contrato. Sin esta cifra, un periodo en el que a media plantilla se le olvido
 * registrar el contrato saldria con una desviacion enorme y con aspecto de dato
 * bueno: cada fila diria «ha trabajado 160 horas por encima de lo contratado»
 * porque lo contratado era cero.
 *
 * Las dos salidas posibles eran suponer un contrato por omision —inventar un
 * dato de nomina, que es exactamente lo que no se hace en este producto— o
 * decirlo. Se dice. Y se dice **en `meta`**, no fila a fila, porque quien lo
 * tiene que leer es quien va a decidir si el informe sirve.
 *
 * ## Cuenta solo dias de relacion laboral
 *
 * Un dia anterior al alta de la persona o posterior a su cese no es un dia «sin
 * contrato»: es un dia en el que no trabajaba aqui. Contarlo llenaria el aviso
 * de ruido en cualquier informe que abarque una incorporacion, y el aviso dejaria
 * de mirarse.
 */
final readonly class ContractCoverage
{
    public function __construct(
        /** Dias-persona del informe con la persona de alta y **sin** contrato vigente. */
        public int $daysWithoutContract,
        /** Cuantas personas distintas tienen al menos uno de esos dias. */
        public int $employeesWithoutContract,
    ) {}

    public function isComplete(): bool
    {
        return $this->daysWithoutContract === 0;
    }
}
