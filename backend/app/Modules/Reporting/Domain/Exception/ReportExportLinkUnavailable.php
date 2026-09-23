<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use DomainException;

/**
 * El enlace de descarga presentado existio y ya no sirve (**RF-IN-06**,
 * ADR-041, decision 3 de la ficha 3.9).
 *
 * ## Dos motivos y `410` para los dos, con `type` distinto
 *
 * - **Usado** — el enlace es de un solo uso. Repetir la misma URL —volver atras
 *   en el navegador, reenviarla— responde
 *   `urn:kronoqr:problem:report-export-link-used`.
 * - **Caducado** — paso la ventana de `REPORTING_EXPORT_LINK_TTL_MINUTES`:
 *   `urn:kronoqr:problem:report-export-link-expired`.
 *
 * `410 Gone` y no `404` porque a quien lo recibe le cambia la accion: el fichero
 * **sigue existiendo** mientras no caduque, y la salida es volver a la pantalla
 * de informes y pedir otro enlace. Un `404` diria «esto no existe, deja de
 * intentarlo». La que si responde `404` es la exportacion purgada o ajena, donde
 * de verdad no hay nada que pedir.
 *
 * **Los dos motivos se distinguen a proposito**, al contrario que en los
 * rechazos de escaneo (regla dura 17). Ahi el atacante prueba tarjetas y
 * cualquier diferencia le dice cual existe; aqui quien llega ya ha presentado un
 * `uuid` v7 correcto —~74 bits aleatorios, porque 48 de sus 122 son marca de
 * tiempo— y lo que necesita saber es si tiene que esperar o si tiene que volver
 * a la pantalla.
 */
final class ReportExportLinkUnavailable extends DomainException
{
    private function __construct(public readonly bool $expired)
    {
        parent::__construct($expired
            ? 'El enlace de descarga ha caducado.'
            : 'El enlace de descarga ya se ha usado.');
    }

    public static function used(): self
    {
        return new self(expired: false);
    }

    public static function expired(): self
    {
        return new self(expired: true);
    }
}
