<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Infrastructure\Persistence\Credential;
use App\Modules\Shared\Application\Port\CredentialFingerprints;

/**
 * El hash del token de las tarjetas activas de un grupo de empleados
 * (RF-KI-03, doc 02 §7.3).
 *
 * **Es la arista de ADR-025**: el puerto vive en `Shared/Application/Port` porque
 * lo necesita `Kiosk` y el dato lo tiene `Identity`, y ninguno de los dos puede
 * importar al otro (§1.6). El enlace se declara en `IdentityServiceProvider`.
 *
 * ## Las tres condiciones de la consulta, y por que estan las tres
 *
 * - **`revoked_at IS NULL`.** Una tarjeta revocada no debe resolverse ni siquiera
 *   sin red: si siguiera en el padron, el quiosco saludaria por su nombre a quien
 *   presenta una tarjeta que el servidor va a rechazar, y el empleado se iria
 *   convencido de haber fichado (RF-QR-03).
 * - **`secret_hash IS NOT NULL`.** Desde ADR-034 el secreto nace al **imprimir**:
 *   una credencial emitida y pendiente de impresion no tiene hash, asi que no hay
 *   nada que resolver. El indice parcial lo aprovecha ademas para no recorrer las
 *   pendientes.
 * - **La lista de empleados.** El filtro por centro no puede hacerse aqui —
 *   `credentials` no sabe de centros— y por eso llega resuelto de
 *   `ClockingEmployees`. Es lo que evita una union entre tablas de dos modulos.
 *
 * ## Sin lista, sin consulta
 *
 * Un `whereIn` con array vacio genera `IN (null)` en algunos motores y una
 * consulta que devuelve todo en otros. Se corta antes: un centro sin empleados
 * activos tiene padron vacio, no un padron con toda la instalacion dentro.
 */
final readonly class EloquentCredentialFingerprints implements CredentialFingerprints
{
    public function forEmployees(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        /** @var list<Credential> $rows */
        $rows = Credential::query()
            ->select(['employee_id', 'secret_hash'])
            ->whereIn('employee_id', $employeeIds)
            ->whereNull('revoked_at')
            ->whereNotNull('secret_hash')
            ->get()
            ->all();

        $fingerprints = [];

        foreach ($rows as $row) {
            $hash = $row->secret_hash;

            if ($hash !== null) {
                $fingerprints[$row->employee_id] = $hash;
            }
        }

        return $fingerprints;
    }
}
