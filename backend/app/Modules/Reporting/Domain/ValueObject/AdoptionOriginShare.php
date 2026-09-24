<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Cuantos fichajes **aceptados** del periodo vinieron de un origen, y que cuota
 * representan (**RF-IN-08**, doc 01 §5.3).
 *
 * ## Los cuatro origenes salen siempre, tambien a cero
 *
 * `import` a cero es informacion: dice que en este periodo no se ha cargado nada
 * de fuera. Omitir los origenes vacios haria que un rosco tuviera tres porciones
 * un mes y cuatro el siguiente, y que la leyenda cambiara de forma sin que haya
 * pasado nada.
 *
 * ## `share` a `null` y no a `0` cuando no hubo ningun fichaje
 *
 * Sin denominador no hay reparto. «Nadie ficho» y «nadie ficho por tarjeta» son
 * afirmaciones distintas, y la segunda, sobre un periodo sin actividad, seria
 * falsa: el 0 % por QR de un hotel cerrado en febrero parece un fallo del quiosco.
 */
final readonly class AdoptionOriginShare
{
    public function __construct(
        /** Valor de `scan_events.origin`: `qr_kiosk`, `pin_kiosk`, `manual_admin` o `import`. */
        public string $origin,
        public int $scans,
        /** De 0 a 100 con dos decimales, o `null` si el periodo no tuvo aceptados. */
        public ?float $share,
    ) {}
}
