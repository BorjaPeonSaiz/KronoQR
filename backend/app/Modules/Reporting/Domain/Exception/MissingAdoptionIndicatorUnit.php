<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use LogicException;

/**
 * Un indicador del cuadro de impacto sin unidad declarada (**RF-IN-08**).
 *
 * ## Es imposible de provocar desde fuera, y por eso existe
 *
 * Solo se alcanza añadiendo un caso a `AdoptionIndicatorKey` y olvidando su fila en
 * la tabla de unidades. Con un `match` sin `default`, PHP se quejaria solo; con una
 * tabla —que es lo que el techo de complejidad de doc 02 §3.5 obliga a usar con doce
 * casos— hay que decirlo a mano, y esta excepcion es ese «a mano».
 *
 * **Y se rompe en voz alta en lugar de suponer una unidad.** Un indicador que cayera
 * a «porcentaje» por omision se pintaria «4860,00 %» donde deberia decir `81:00`: un
 * numero absurdo en la pantalla que sostiene una renovacion de licencia. Mejor un
 * fallo que nadie puede confundir con un dato.
 */
final class MissingAdoptionIndicatorUnit extends LogicException
{
    public function __construct(string $key)
    {
        parent::__construct(
            'El indicador «'.$key.'» del cuadro de impacto no tiene unidad declarada: '
            .'anade su fila en AdoptionIndicatorKey.',
        );
    }
}
