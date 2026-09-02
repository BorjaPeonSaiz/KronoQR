<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver y cambiar el perfil de cumplimiento (RF-PD-07, regla dura 18).
 *
 * ## Solo `admin`, con el mismo razonamiento que la configuracion — y uno mas
 *
 * El §7.3 del doc 02 concede `settings:*` unicamente al administrador de
 * instalacion, y este recurso viaja bajo ese ambito. El middleware `ability`
 * comprueba el ambito y esta policy el rol: dos controles distintos, que aqui
 * coinciden. Sin la policy, bastaria un token emitido a mano para mover el
 * umbral con el que se decide si una jornada incumple el Estatuto.
 *
 * **`rrhh` no entra, y aqui pesa mas que en la configuracion.** Quien corrige un
 * fichaje deja traza sobre una jornada y con motivo; quien baja `min_rest_hours`
 * hace que dejen de saltar las alertas de descanso insuficiente de **toda** la
 * plantilla, hacia delante, sin que ninguna jornada cambie de aspecto. Son dos
 * potestades distintas y la segunda es la del responsable de la instalacion.
 *
 * **El `auditor` tampoco.** Es el rol que mira, y lo que necesita —que umbral
 * regia el 14 de marzo y quien lo cambio— esta en `audit_log`, al que llega con
 * `audit:read`, y ahi es historico y encadenado en lugar de ser el valor de hoy.
 *
 * ## Dos metodos aunque el conjunto de roles sea el mismo
 *
 * Para que la matriz de autorizacion negativa pruebe cada endpoint por separado
 * (regla dura 18): un `authorize()` que devolviera `true` en uno solo de los dos
 * seria invisible desde el otro.
 */
final class ComplianceProfilePolicy
{
    /**
     * @return list<UserRole>
     */
    private static function administrators(): array
    {
        return [UserRole::ADMIN];
    }

    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }

    public function update(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }
}
