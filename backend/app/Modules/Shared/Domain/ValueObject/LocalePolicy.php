<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Los idiomas que la instalacion ofrece y con cual responde por defecto
 * (RF-PD-01, RF-PD-08, regla dura 13).
 *
 * ## Por que es un objeto y no dos cadenas sueltas
 *
 * Porque tienen una invariante entre ellas —**el idioma por defecto esta siempre
 * entre los disponibles**— y separadas no hay donde escribirla. Sin ella, un
 * `PATCH` que quitara `es` de la lista sin tocar el idioma por defecto dejaria
 * la instalacion respondiendo en un idioma que su propio selector no ofrece: el
 * quiosco enseñaria dos banderas y la API contestaria en una tercera.
 *
 * `ResolvedSettings::with()` comprueba la misma invariante al guardar, y debe
 * hacerlo: alli el error es un `422` con un mensaje util, y aqui es la garantia
 * de que nadie construye el objeto roto por otro camino —una consola, el
 * instalador, una fila editada a mano—.
 *
 * ## Vive en `Shared` y no en `Product`
 *
 * Por el criterio de ADR-021 y ADR-025: lo consumen el borde HTTP que negocia el
 * idioma de cada respuesta y el endpoint que publica la marca, y no es regla de
 * negocio de ninguno de los dos.
 */
final readonly class LocalePolicy
{
    /**
     * @param  list<string>  $available  en el orden en el que se ofrecen
     */
    public function __construct(
        public string $default,
        public array $available,
    ) {
        if ($available === []) {
            // Una instalacion sin ningun idioma no puede responder nada. El
            // catalogo lo impide, pero este objeto se construye tambien desde el
            // respaldo de configuracion, que si podria venir vacio.
            throw new InvalidArgumentException('La instalacion tiene que ofrecer al menos un idioma.');
        }

        if (! \in_array($default, $available, true)) {
            throw new InvalidArgumentException(
                'El idioma por defecto «'.$default.'» no esta entre los disponibles ('.implode(', ', $available).').',
            );
        }
    }
}
