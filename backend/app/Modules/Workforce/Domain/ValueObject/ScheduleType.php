<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Tipo de jornada pactado en el contrato (**RF-GP-02**, doc 01 §5.5).
 *
 * **Los tres valores estan en castellano y es la unica excepcion consciente a
 * «el codigo se escribe en ingles»** (doc 02 §3.5): no son identificadores del
 * codigo sino el **catalogo literal** que el doc 01 §5.5 fija para la columna
 * —`continua`|`partida`|`turnos`—, igual que `CorrectionReasonCode` conserva los
 * codigos del Anexo C. Traducirlos aqui obligaria a mantener un diccionario
 * entre el esquema, el contrato y esta clase, que es exactamente la clase de
 * discrepancia silenciosa que el catalogo cerrado existe para impedir. El
 * **nombre** del caso si va en ingles.
 *
 * **Hoy no cambia ningun calculo, y no es un descuido.** Lo contratado se
 * prorratea por dias naturales de vigencia a partir de `weekly_hours`
 * (RF-IN-03), sin mirar el tipo de jornada: distinguir «partida» de «continua»
 * exigiria un horario teorico por dia de la semana, que este producto no
 * modela y que ningun requisito pide todavia. El campo se guarda porque el doc
 * 01 lo exige y porque es el dato que RRHH ya tiene en la mano al dar de alta un
 * contrato; el dia que exista un cuadrante teorico, estara.
 */
enum ScheduleType: string
{
    /** Jornada continua: una sola entrada y una sola salida al dia. */
    case Continuous = 'continua';

    /** Jornada partida: dos tramos con una pausa larga en medio (ADR-024). */
    case Split = 'partida';

    /** A turnos, incluidos los nocturnos que no se parten a medianoche (RN-05). */
    case Shifts = 'turnos';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
