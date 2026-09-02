<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * El fichero de plantilla no se puede leer (**RF-GP-05**).
 *
 * No es una linea mala: es que no hay lineas que leer. Un XLSX corrupto, un CSV
 * sin cabecera, un fichero vacio o uno cuyo formato no reconoce el lector.
 *
 * **`422` colgado de `file`**, no `500`: quien lo recibe tiene algo que hacer
 * —volver a exportar el fichero— y el mensaje se lo dice. Es la unica salida de
 * este endpoint que no es un informe: cualquier otra cosa, incluidas cuarenta
 * lineas rechazadas, sale con `200` y su informe.
 */
final class UnreadableImportFile extends WorkforceDomainException
{
    public const string TRANSLATION_KEY = 'import.unreadable_file';

    public readonly string $translationKey;

    public function __construct(string $reason)
    {
        $this->translationKey = self::TRANSLATION_KEY;

        parent::__construct('The staff import file cannot be read: '.$reason);
    }
}
