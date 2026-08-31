<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

use RuntimeException;

/**
 * Raiz de las excepciones del dominio de `Product`.
 *
 * Existe para que quien captura pueda distinguir «la configuracion ha dicho que
 * no» de «algo se ha roto», sin enumerar subclases: el controlador de
 * `PATCH /api/v1/settings` traduce esta rama a 422 y deja pasar el resto como
 * 500. Extiende `RuntimeException` de PHP y no una clase del framework: el
 * dominio es puro (regla dura 1).
 */
abstract class ProductDomainException extends RuntimeException {}
