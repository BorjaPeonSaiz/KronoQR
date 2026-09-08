<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

use App\Modules\Product\Domain\Model\DataExport;

/**
 * Se ha pedido una exportacion integra mientras hay otra `pending` o `running`
 * (**RF-PD-14**).
 *
 * **Una sola en curso por instalacion** (decision 4 de la ficha 5.10): no hay
 * ninguna razon para generar dos copias completas a la vez, y dos recorridos
 * simultaneos de todas las tablas si tendrian coste sobre la base de datos por
 * la que pasa cada fichaje (ADR-010).
 *
 * **Lleva dentro la exportacion que ya esta en curso**, y no solo su `uuid`,
 * porque el contrato exige que el `409` devuelva esa fila en el cuerpo: el panel
 * enseña la que hay en lugar de pedir otra, que es lo unico util que puede
 * hacer. Que la traiga la excepcion evita que el borde tenga que abrir un
 * segundo camino de lectura de la tabla para componer su propia respuesta.
 *
 * `current` puede ser `null` en un caso: la que ocupaba el turno termino entre
 * el choque contra el indice y la relectura. Ahi el `409` sigue siendo correcto
 * —esta peticion no llego a crearse— y lo unico que se puede decir es que se
 * reintente.
 */
final class DataExportAlreadyInProgress extends ProductDomainException
{
    public function __construct(public readonly ?DataExport $current)
    {
        parent::__construct(\sprintf(
            'A data export is already in progress: "%s".',
            $current->uuid ?? 'unknown',
        ));
    }
}
