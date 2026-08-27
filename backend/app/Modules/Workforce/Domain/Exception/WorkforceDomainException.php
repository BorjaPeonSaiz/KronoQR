<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use DomainException;

/**
 * Raiz de las excepciones de dominio de Workforce.
 *
 * Existe para que la capa HTTP pueda distinguir «el dominio ha dicho que no» de
 * «algo se ha roto» sin enumerar cada caso: lo primero es una respuesta con
 * significado para quien la recibe, lo segundo es un 500 y una entrada en el
 * historico de errores.
 */
abstract class WorkforceDomainException extends DomainException {}
