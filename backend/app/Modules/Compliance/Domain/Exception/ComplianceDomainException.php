<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

use RuntimeException;

/**
 * Raiz de las excepciones del dominio de `Compliance`.
 *
 * Existe para que quien captura pueda distinguir «el dominio ha dicho que no»
 * de «algo se ha roto», sin enumerar subclases. Extiende `RuntimeException` de
 * PHP y no una clase del framework: el dominio es puro (regla dura 1).
 */
abstract class ComplianceDomainException extends RuntimeException {}
