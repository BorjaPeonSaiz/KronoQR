<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\Exception;

/**
 * El nombre del quiosco esta vacio o pasa de 120 caracteres.
 *
 * El borde HTTP lo para antes con un `422` colgado del campo `name`, que es lo
 * util para quien rellena un formulario. Esto es la red que sostiene los otros
 * caminos —la consola— y la garantia de que la invariante no depende de por donde
 * se entre.
 */
final class InvalidDeviceName extends KioskDomainException {}
