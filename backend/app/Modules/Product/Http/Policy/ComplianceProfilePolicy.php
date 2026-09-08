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

    /**
     * **Un acceso de soporte NO cambia el perfil de cumplimiento** (RF-PD-11,
     * RL-01, RL-02, regla dura 14, decision de `seguridad-cumplimiento` de la
     * tarea 5.9).
     *
     * ## Por que esta es la excepcion dentro de `configuration`
     *
     * El alcance `configuration` lleva `settings:*`, y ese ambito cubre tambien
     * el perfil de cumplimiento (doc 02 §7.3, precision 4). Para casi todo lo
     * que abre es correcto: un umbral operativo mal puesto, un quiosco que no
     * vincula, una zona horaria equivocada. **El perfil no es eso.**
     *
     * Lo que hay aqui son los **umbrales legales** —descanso minimo, jornada
     * maxima, pausas— y `retention_years`, que es cuantos años se conserva el
     * registro horario (RL-02). Los fija la **jurisdiccion del cliente y su
     * asesoria**, no quien esta arreglando una incidencia tecnica: cambiar uno
     * mueve las incidencias que se detectan sobre las horas ya trabajadas de
     * toda la plantilla, y bajar `retention_years` acorta la vida de un registro
     * con valor probatorio. Ninguna de las dos cosas se hace sin que el obligado
     * legal lo decida.
     *
     * **Que la ruta siga siendo alcanzable por ambito y la cierre la policy es
     * exactamente el diseño del §7.3**, no un descuido: son dos controles, y
     * este es el caso en el que el segundo dice algo que el primero no sabe
     * decir. `SupportScopeRoutesTest` lo deja anotado en su lista.
     *
     * **`view()` sigue abierto**: leer los umbrales es justo lo que hace falta
     * para diagnosticar por que salta una incidencia, y no cambia nada.
     */
    public function update(ManagementActor $actor): bool
    {
        if ($actor->isSupportActor()) {
            return false;
        }

        return $actor->actsAs(...self::administrators());
    }
}
