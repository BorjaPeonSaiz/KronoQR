<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Policy;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede pedir el informe de horas por periodo (**RF-IN-01**, Anexo B del
 * doc 01, regla dura 18).
 *
 * ## «manager+», que aqui son dos roles y no tres
 *
 * `{admin, rrhh}`. El `responsable_departamento` **no entra**, y no es un olvido:
 * el §7.3 no le da `reports:*` —lleva `attendance:read`, `attendance:correct`,
 * `incidents:*` y `employees:read`—, asi que ni siquiera pasa del middleware
 * `ability`. La policy dice lo mismo que el ambito, que es como tienen que ser
 * las dos comprobaciones: si dijeran cosas distintas, una de las dos estaria de
 * adorno.
 *
 * Eso **no** significa que el filtro por departamento sobre. Lo usan `admin` y
 * `rrhh` cuando quieren el informe de una sola area, que es la pregunta normal
 * de una reunion de nomina. Y el alcance por departamento sigue entrando en la
 * consulta (RF-ID-03), preparado para el dia en que el producto decida que un
 * responsable ve las horas de su equipo: ese dia se le añade el ambito y este
 * rol, y la consulta ya esta acotada.
 *
 * ## Por que el `auditor` no esta, teniendo un ambito de informes
 *
 * Lleva `reports:legal`, que es el estrecho: le abre `GET
 * /reports/legal-export` y nada mas. Lo suyo es el registro normalizado para un
 * requerimiento (RF-IN-05, RL-03), no el cuadro de horas trabajadas frente a
 * contratadas, que es una herramienta de gestion de personal. Auditar es mirar
 * lo que quedo escrito.
 *
 * ## La policy es la mitad de la autorizacion
 *
 * La otra es el ambito `reports:*`, que el middleware `ability` verifica antes.
 * Con las dos, un token de quiosco no llega aqui aunque su portador tuviera rol,
 * y una cuenta con el ambito pero sin rol no ve a nadie.
 *
 * **Se registra contra {@see PeriodReport}, que es un objeto de dominio y no un
 * modelo Eloquent**, igual que las hermanas de este directorio: asi la
 * autorizacion se decide **antes** de tocar la base de datos.
 */
final class PeriodReportPolicy
{
    /**
     * Roles que pueden pedir el informe de horas de terceros.
     *
     * Metodo y no constante por lo mismo que en las hermanas: el conjunto puede
     * cambiar, y lo que no cambia —el alcance— se resuelve, no se enumera.
     *
     * @return list<UserRole>
     */
    private static function readers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    /** `GET /api/v1/reports/period`. */
    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::readers());
    }
}
