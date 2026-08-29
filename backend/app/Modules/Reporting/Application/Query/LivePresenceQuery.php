<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Query;

use App\Modules\Reporting\Domain\ValueObject\PresenceStatus;
use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * Lo que se pide al panel de presencia (**RF-PA-01**, RF-PA-02).
 *
 * **El alcance va dentro de la consulta y no al lado.** Es lo que quien llama no
 * puede elegir (RF-ID-03): lo resuelve la capa HTTP a partir del token y viaja
 * junto a los filtros para que no exista ninguna ruta por la que se pueda
 * consultar sin el.
 *
 * **`departmentId` es un filtro, no una autorizacion.** Un departamento fuera
 * del alcance no produce `403`: produce un resultado vacio, igual que en
 * `GET /api/v1/employees`. Un `403` al filtrar convertiria el desplegable de
 * departamentos del panel en un generador de errores, y ademas diria que ese
 * departamento existe.
 */
final readonly class LivePresenceQuery
{
    public function __construct(
        public AccessScope $scope,
        public ?int $departmentId,
        /** Termino libre ya recortado, o `null` si no lo hubo. */
        public ?string $search,
        public PresenceStatus $status,
    ) {}
}
