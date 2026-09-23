<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * A quien se avisa de que su informe esta listo (**RF-IN-06**, decision 8 de la
 * ficha 3.9).
 *
 * ## Por que un puerto y no el modelo `User`
 *
 * `Reporting` no puede importar `Identity` (doc 02 §1.6, verificado por
 * Deptrac), y tampoco lo necesita: de la cuenta hacen falta tres datos —el
 * nombre con el que saludar, la direccion y el idioma— y ninguno exige el
 * agregado entero. Es el mismo criterio y el mismo precedente que
 * `ReportIssuerDirectory`, que lee `users.name` para sellar el pie del PDF: una
 * consulta de tres columnas sobre la tabla, no un `use` del modelo de otro
 * modulo.
 *
 * ## Puede devolver `null`, y no es un error
 *
 * Una cuenta desactivada, o borrada en una instalacion antigua, deja la fila sin
 * destinatario. Ahi el aviso es el que siempre existe —la pantalla— y la fila
 * queda con canal `panel`. El informe sigue generado y descargable: **el producto
 * no depende del correo** (regla dura 12).
 */
interface ReportExportRecipients
{
    public function find(int $userId): ?ReportExportRecipient;
}
