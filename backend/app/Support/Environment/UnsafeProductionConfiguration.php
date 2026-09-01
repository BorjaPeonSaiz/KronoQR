<?php

declare(strict_types=1);

namespace App\Support\Environment;

use RuntimeException;

/**
 * La instalacion esta configurada de una forma que no puede atender trafico.
 *
 * Excepcion propia y no una `RuntimeException` pelada para que la prueba pueda
 * afirmar el caso concreto —y no «lanza algo»— y para que quien la vea en el
 * log sepa, por el nombre de la clase, que se trata de la configuracion del
 * despliegue y no de un defecto del producto.
 */
final class UnsafeProductionConfiguration extends RuntimeException {}
