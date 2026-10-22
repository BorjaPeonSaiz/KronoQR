<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * La fase en que esta una exportacion `running` (**V5-PA-4**, RF-IN-06).
 *
 * Tres, y en este orden: se lee el registro, se escribe el fichero y se cierra
 * —huella, tamaño y asiento—. Son las que se pueden afirmar **sin inventar**:
 * quien genera sabe en cual esta, y una pantalla que dice «escribiendo el
 * fichero» le cuenta a quien espera algo verdadero aunque no haya porcentaje.
 *
 * No hay fase `queued`: eso es el estado `pending` de la fila, que es la
 * fuente de verdad. La fase solo existe mientras la fila esta `running`.
 */
enum ReportExportStage: string
{
    case Reading = 'reading';

    case Writing = 'writing';

    case Sealing = 'sealing';
}
