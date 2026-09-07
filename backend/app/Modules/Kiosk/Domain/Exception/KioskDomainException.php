<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\Exception;

use RuntimeException;

/**
 * Raiz de las excepciones de dominio de `Kiosk`.
 *
 * Existe para que el borde pueda capturar «algo del dominio del quiosco se
 * quejo» sin enumerar clases, igual que `ProductDomainException`. **No lleva
 * texto de usuario**: el dominio no sabe en que idioma se va a leer (ver
 * `ProblemDetails::translated()`).
 */
abstract class KioskDomainException extends RuntimeException {}
