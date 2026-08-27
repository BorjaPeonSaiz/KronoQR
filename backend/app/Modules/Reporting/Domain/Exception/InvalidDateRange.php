<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use InvalidArgumentException;

/**
 * Un rango de jornadas que no se puede consultar.
 *
 * La validacion de la peticion ya devuelve un `422` con el mensaje para quien
 * llama; esto es la segunda linea, la que protege a la consulta de una llamada
 * interna —un comando de consola, una exportacion— que no pase por un
 * `FormRequest`.
 */
final class InvalidDateRange extends InvalidArgumentException {}
