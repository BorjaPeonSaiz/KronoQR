<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use InvalidArgumentException;

/**
 * Una semana que no existe en el calendario ISO 8601.
 *
 * Son dos casos y los dos llegan por `reporting:weekly-summary --week=`: una
 * forma que no es `AAAA-Www` y una semana 53 de un año que solo tiene 52. El
 * segundo es el que justifica la clase: `2026-W53` parece una semana y no lo es,
 * y sin esta comprobacion el comando habria reenviado la primera semana de 2027
 * rotulada como la ultima de 2026.
 */
final class InvalidIsoWeek extends InvalidArgumentException {}
