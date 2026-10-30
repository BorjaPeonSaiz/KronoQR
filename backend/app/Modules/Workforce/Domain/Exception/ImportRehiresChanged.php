<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Las readmisiones que haria la aplicacion **no son las que se revisaron**
 * (RN-27, RF-GP-05, ADR-046 §5).
 *
 * `apply` vuelve a comprobar el fichero, y entre la validacion y la aplicacion
 * la plantilla puede haber cambiado: una baja registrada en ese hueco
 * convertiria un `update` revisado en una readmision que nadie ha visto, y una
 * readmision hecha desde la ficha quitaria una del resumen. La huella de las
 * readmisiones (`rehire_digest`) lo detecta y la aplicacion entera se detiene
 * sin escribir nada, como con un fichero distinto ({@see ImportFileChanged}).
 *
 * `409`: lo que no encaja es el estado. Se vuelve a validar.
 */
final class ImportRehiresChanged extends WorkforceConflict implements BusinessRejection
{
    public static function make(): self
    {
        return new self(
            'Las personas que se van a dar de alta de nuevo ya no son las del resumen revisado. '
            .'Vuelve a comprobar el fichero y aplica con el resumen nuevo.',
        );
    }
}
