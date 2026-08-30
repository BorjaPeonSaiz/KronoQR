<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Command;

use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * La orden de dar una incidencia por trabajada (RF-PA-05).
 *
 * **El autor no se declara, se toma de la sesion.** `resolvedByUserId` lo rellena
 * el `FormRequest` con el `users.id` del token, nunca con algo del cuerpo:
 * aceptarlo en la peticion permitiria firmar la resolucion de otra persona, que
 * es exactamente lo que un registro con valor probatorio no puede admitir
 * (RN-13). Es la misma decision que en las tres peticiones de correccion.
 *
 * **El alcance viaja en el comando** y no lo resuelve el caso de uso, por lo
 * mismo: hasta donde llega quien pregunta se deduce de su token y de nada mas
 * (RF-ID-03). Que sea un parametro obligatorio es lo que impide que un camino
 * nuevo hacia esta operacion se escriba sin decidir el alcance.
 */
final readonly class ResolveIncidentCommand
{
    public function __construct(
        public int $incidentId,
        /** `Resolved` o `Dismissed`. El dominio rechaza `Open`: reabrir no es un desenlace. */
        public IncidentStatus $outcome,
        /** Obligatoria y ya recortada (RN-13). El dominio vuelve a rechazarla si viene en blanco. */
        public string $note,
        public int $resolvedByUserId,
        public AccessScope $scope,
    ) {}
}
