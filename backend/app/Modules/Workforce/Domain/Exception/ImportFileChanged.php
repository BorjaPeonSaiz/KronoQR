<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * El fichero que se manda aplicar **no es el que se valido** (RF-GP-05).
 *
 * ## Por que existe esta comprobacion
 *
 * Porque la confirmacion de RF-GP-05 tiene que ser una confirmacion **de algo
 * concreto**. Sin ella, la fase de aplicacion aceptaria cualquier fichero: quien
 * revisa un informe de «38 altas y 2 rechazos», corrige el fichero y lo vuelve a
 * subir, estaria aplicando a ciegas un contenido que nadie ha revisado. Con el
 * `sha256` de vuelta, aplicar solo puede hacer lo que se leyo en pantalla.
 *
 * Es tambien lo que permite **no guardar el fichero en el servidor** entre las
 * dos fases: un almacen temporal con los nombres y los documentos de identidad
 * de la plantilla es superficie de datos personales en reposo, con su borrado,
 * su cuota y su fuga.
 *
 * `409` y no `422`: la peticion es valida y lo que no encaja es el estado —lo
 * revisado y lo enviado son distintos—. La accion siguiente es volver a validar,
 * no corregir un campo.
 */
final class ImportFileChanged extends WorkforceConflict
{
    public static function make(): self
    {
        return new self(
            'El fichero enviado no es el que se valido. Vuelve a validarlo y aplica con el resumen nuevo: '
            .'asi lo que se escribe es exactamente lo que has revisado.',
        );
    }
}
