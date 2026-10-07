<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver y tocar los departamentos (regla dura 18).
 *
 * - **Ver**: los cuatro roles de gestion —`admin`, `rrhh`,
 *   `responsable_departamento` y `auditor`— (decision del propietario de
 *   02-10-2026, bloque 21 de la 2.2.0, R6-BD-01 y R4-QA-03).
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
 * El ambito lo comprueba antes la ruta: `employees:read` o `attendance:read`
 * para leer (el `auditor` solo lleva el segundo), `employees:*` para escribir.
 * Ninguno de los dos de lectura lo tienen el quiosco ni el portal.
 *
 * **Un acceso de soporte no lee el catalogo**, aunque el alcance `read_only`
 * lleve los dos ambitos de lectura y se presente como `admin`. Hasta el bloque
 * 21 la ruta exigia `employees:*`, que ningun alcance concede, asi que abrir la
 * lectura a los cuatro roles no debe ampliar de rebote lo que ve el fabricante
 * (regla dura 16, ADR-020): `manager_name` es el nombre de una cuenta de
 * gestion, y esas cuentas solo las lista `admin` sin soporte
 * (`ManagementAccountPolicy`). Lo enumera `SupportScopeRoutesTest`.
 */
final class DepartmentPolicy
{
    /** @return list<UserRole> */
    private static function readers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH, UserRole::RESPONSABLE_DEPARTAMENTO, UserRole::AUDITOR];
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
