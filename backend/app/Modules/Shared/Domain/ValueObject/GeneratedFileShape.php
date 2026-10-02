<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Que forma tiene cada entrada de una clase de fichero (ADR-045, C1, C3).
 *
 * Un ZIP o un temporal son un fichero regular en la raiz de su clase; un informe
 * en diferido o un espacio de trabajo son un **directorio** de un nivel con
 * ficheros regulares dentro. La forma decide como se lista, como se mide la
 * edad y como se borra: un directorio se vacia fichero a fichero y se retira, y
 * si dentro aparece algo que no es un fichero regular, se deja intacto.
 */
enum GeneratedFileShape
{
    case File;

    case Directory;
}
