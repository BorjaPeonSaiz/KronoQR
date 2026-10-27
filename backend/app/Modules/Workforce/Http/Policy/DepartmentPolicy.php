<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver y tocar los departamentos (regla dura 18).
 *
 * - **Ver**: `admin`, `rrhh` y `responsable_departamento` (decision del
 *   propietario de 02-10-2026). **El `auditor` no**, desde la decision del
 *   13-10-2026 (RF-ID-02): su unica funcion en la API es
 *   la exportacion para la Inspeccion. Se le rechaza aqui por rol y no solo por
 *   ambito porque un token emitido antes del cambio conserva `attendance:read`
 *   hasta que caduca, y con el la ruta le dejaria pasar.
 * - **Crear y renombrar**: `admin` y `rrhh`.
 * - **Elegir el responsable**: solo `admin`, y nunca un acceso de soporte
 *   ({@see self::assignManager()}, RF-ID-10).
 *
 * **La lectura no se acota por departamento.** El listado es un catalogo de la
 * instalacion —identificador, nombre y quien lo dirige—, no datos de personas:
 * un `responsable_departamento` ve tambien los departamentos que no dirige. Su
 * alcance (RF-ID-03) se aplica sobre las personas de sus departamentos
 * (`ScopeGuard`, `EmployeeQueries`), no sobre este catalogo, que no administra.
 * El contrato lo declara asi (`GET /api/v1/departments`) y el esquema
 * `Department` ya contaba con que los responsables vieran `manager_name`.
 *
 * El ambito lo comprueba antes la ruta: `employees:read` para leer,
 * `employees:*` para escribir. Ninguno lo tienen el quiosco ni el portal.
 *
 * **Un acceso de soporte no lee el catalogo**, aunque el alcance `read_only`
 * lleve `employees:read` y se presente como `admin`: abrir la lectura a los
 * roles de gestion no debe ampliar de rebote lo que ve el fabricante (regla
 * dura 16, ADR-020): `manager_name` es el nombre de una cuenta de gestion, y
 * esas cuentas solo las lista `admin` sin soporte (`ManagementAccountPolicy`).
 * Lo enumera `SupportScopeRoutesTest`.
 */
final class DepartmentPolicy
{
    /** @return list<UserRole> */
    private static function readers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH, UserRole::RESPONSABLE_DEPARTAMENTO];
    }

    private static function canRead(ManagementActor $actor): bool
    {
        return ! $actor->isSupportActor() && $actor->actsAs(...self::readers());
    }

    /** @return list<UserRole> */
    private static function writers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    public function viewAny(ManagementActor $actor): bool
    {
        return self::canRead($actor);
    }

    public function view(ManagementActor $actor): bool
    {
        return self::canRead($actor);
    }

    public function create(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }

    public function update(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }

    /**
     * Elegir el responsable de un departamento (RF-ID-10, ADR-051 §5): **solo
     * `admin`, y nunca un acceso de soporte**.
     *
     * Elegir responsable es elegir entre las cuentas de gestion, que solo
     * `admin` puede listar, y conceder alcance sobre personas. Un acceso de
     * soporte se presenta como `admin` ante las policies, y por eso se le
     * rechaza aparte, igual que `ManagementAccountPolicy`: el ambito
     * `accounts:*` que exige ademas el `FormRequest` no lo concede ningun
     * alcance de soporte, pero se quieren dos controles y no uno.
     */
    public function assignManager(ManagementActor $actor): bool
    {
        return ! $actor->isSupportActor() && $actor->actsAs(UserRole::ADMIN);
    }
}
