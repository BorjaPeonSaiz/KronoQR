<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use DomainException;

/**
 * Se ha intentado mover un informe en diferido desde un estado que no lo admite
 * (**RF-IN-06**).
 *
 * ## No es un error de quien pide, es un error del producto
 *
 * Ninguna peticion HTTP puede provocar esto: el `409` de «ya tienes una en
 * curso» lo resuelve el indice unico antes, y la descarga comprueba el estado.
 * Si esto se lanza es porque dos caminos del servidor han intentado cerrar la
 * misma fila —el trabajo y el `failed()` del trabajo, o la purga y una
 * generacion tardia— y el orden no era el previsto. Por eso sale como `500` y no
 * se traduce a `problem+json` con explicacion: hay algo que mirar en el log, no
 * una accion que ofrecerle a quien lo recibe.
 *
 * **Existe para que una fila no pueda resucitar.** Sin esta guarda, un
 * `complete()` tardio sobre una exportacion que la obsolescencia ya dio por
 * muerta la dejaria como descargable apuntando a un fichero que se borro, y el
 * panel ofreceria una descarga que responde `404`.
 *
 * El mensaje lleva el estado y la accion, nunca el `uuid` ni nada de la fila: es
 * un mensaje de excepcion y acaba en el log tecnico (regla dura 21).
 */
final class InvalidReportExportTransition extends DomainException
{
    public static function from(ReportExportStatus $status, string $action): self
    {
        return new self('No se puede '.$action.' una exportacion de informe en estado '.$status->value.'.');
    }
}
