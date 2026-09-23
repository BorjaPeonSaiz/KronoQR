<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

use App\Modules\Reporting\Domain\Model\ReportExport;
use DateTimeImmutable;

/**
 * Una exportacion y, si estaba lista, el enlace recien acuñado (**RF-IN-06**,
 * ADR-041).
 *
 * ## Por que no vive en `Domain/`
 *
 * Porque **lleva un secreto dentro**, y un secreto no es vocabulario del
 * dominio: el modelo {@see ReportExport} guarda la huella del token y nunca el
 * token. Este objeto existe solo para cruzar del caso de uso al controlador con
 * las dos cosas que este necesita para componer la respuesta —la fila y la
 * llave— y muere ahi.
 *
 * ## Los tres campos van juntos a proposito
 *
 * `token` y `expiresAt` son nulos exactamente en el mismo caso: cuando la
 * exportacion no esta descargable o su fichero ya caduco. Devolverlos por
 * separado obligaria a cada consumidor a decidir que hacer con «token sin fecha»,
 * que es un estado que no existe.
 */
final readonly class IssuedReportExportLink
{
    public function __construct(
        public ReportExport $export,
        /** El token en claro. **Nunca se registra ni se guarda**: viaja a la URL y se olvida. */
        public ?string $token,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
