<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use App\Modules\Shared\Domain\Support\UtcInstant;

/**
 * La forma de una hora UTC escrita para una persona, **sin `Z`**, en un texto
 * que ya dice «UTC» al lado: un correo, una linea de consola, un `detail` de
 * error, un informe de texto.
 *
 * Es la otra cara de {@see UtcPrecision}: alli el patron lleva la `Z` y la
 * marca del contrato; aqui el rotulo lo pone el texto que rodea a la hora. Las
 * dos comparten la trampa: `format()` no convierte, y una hora que llega en
 * otra zona saldria rotulada «UTC» sin serlo. Por eso **solo**
 * {@see UtcInstant::wallTime()} usa estos patrones, despues de pasar a UTC.
 */
enum UtcWallTime: string
{
    /** `2026-10-25 01:30` */
    case Minute = 'Y-m-d H:i';

    /** `2026-10-25 01:30:15` */
    case Second = 'Y-m-d H:i:s';

    /** `01:30`, cuando la fecha ya la dice el texto o no hace falta. */
    case TimeOfDay = 'H:i';

    /**
     * `20261025-013015`, para el nombre de un fichero que no lleva `Z`
     * (`retencion-propuesta-…`): el nombre ya lo leen los scripts de rescate
     * con esta forma, y la hora tiene que ser UTC igual.
     */
    case FileStamp = 'Ymd-His';
}
