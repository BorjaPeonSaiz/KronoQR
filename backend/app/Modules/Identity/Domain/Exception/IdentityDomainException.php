<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use DomainException;

/**
 * Raiz de las excepciones de dominio de `Identity`.
 *
 * Existe por el mismo motivo que la de `Workforce`: la capa de arriba tiene que
 * poder distinguir «esto lo prohibe una regla de negocio» de «esto se ha roto»,
 * y hacerlo sin enumerar cada subclase. Hereda de `DomainException` de PHP, que
 * no arrastra nada de infraestructura.
 *
 * El paralelismo con el otro modulo se cuenta en prosa y **no con un `{@see}`**:
 * una referencia asi la convierte Pint en un `use`, y un `use` de
 * `Workforce\Domain` desde aqui es una violacion de la regla dura 1 que Deptrac
 * tumba — un comentario no puede abrir una frontera.
 */
abstract class IdentityDomainException extends DomainException {}
