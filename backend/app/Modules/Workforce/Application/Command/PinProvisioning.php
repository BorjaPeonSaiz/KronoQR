<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Que hace el alta con el PIN de la persona (RF-ID-09, RF-GP-05).
 *
 * **Una decision explicita, nunca un nulo.** «Sin PIN» no puede ser lo que pasa
 * cuando alguien olvida un argumento: por eso el valor de serie de
 * {@see RegisterEmployeeCommand::$pin} es {@see self::IssueNow}, y diferir hay
 * que pedirlo por su nombre.
 */
enum PinProvisioning
{
    /**
     * El alta emite el PIN en su misma transaccion y lo devuelve una sola vez
     * (RF-ID-09). El de serie, y el unico del alta individual: quien da de alta
     * a una persona la tiene delante y le entrega el PIN con la tarjeta.
     */
    case IssueNow;

    /**
     * La persona nace con el PIN **pendiente**, y RRHH lo emite desde su ficha
     * al entregarle la tarjeta (`POST /employees/{uuid}/pin/reset`).
     *
     * Solo la importacion masiva (RF-GP-05): un PIN que se muestra una vez no
     * cabe en un informe de quinientas filas, y emitirlo para no ensenarlo
     * dejaria quinientos secretos que nadie ha visto y que habria que volver a
     * emitir igualmente. El pendiente queda a la vista en el listado
     * (`pin_status=pending`), no escondido.
     */
    case DeferredToCardHandover;
}
