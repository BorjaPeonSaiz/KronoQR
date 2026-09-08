<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\DoctorFinding;

/**
 * Traduce las claves de {@see DoctorFinding}
 * al idioma pedido (RF-PD-13, «textos en español e ingles»).
 *
 * Existe para que el caso de uso no importe el traductor del framework y, sobre
 * todo, para que **el idioma sea un argumento y no el estado global del
 * proceso**: `product:doctor --lang=en` no puede cambiar el locale de la
 * aplicacion, porque el mismo proceso puede estar sirviendo un paquete de
 * diagnostico pedido desde el panel en español.
 */
interface DoctorTranslator
{
    /**
     * @param  array<string, string|int|float|bool|null>  $params
     * @return string|null Nulo si la clave no existe, para que quien pregunta
     *                     decida —el `fix` de una comprobacion en `ok` no
     *                     existe y eso es correcto.
     */
    public function translate(string $key, array $params, string $locale): ?string;
}
