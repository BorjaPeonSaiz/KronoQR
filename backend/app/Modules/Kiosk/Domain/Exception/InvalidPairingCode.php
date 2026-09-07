<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\Exception;

use App\Modules\Kiosk\Domain\ValueObject\ConfirmOutcome;

/**
 * El codigo no tiene la forma de un codigo de emparejamiento.
 *
 * **No es el rechazo de un codigo que no existe.** Aquel es un desenlace normal y
 * generico ({@see ConfirmOutcome::Rejected});
 * esto es una cadena que ni siquiera son seis digitos, y solo puede llegar desde
 * la consola o desde un cliente que se salta el contrato — el borde HTTP lo para
 * antes con un `422` de validacion.
 */
final class InvalidPairingCode extends KioskDomainException
{
    public function __construct()
    {
        parent::__construct('Un codigo de emparejamiento son seis digitos decimales.');
    }
}
