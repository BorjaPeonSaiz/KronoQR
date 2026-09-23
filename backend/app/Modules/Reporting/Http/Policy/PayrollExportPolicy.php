<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede sacar la **salida a nomina** (**RF-IN-07**, Anexo B del doc 01,
 * regla dura 18).
 *
 * ## «rrhh+», que son dos roles
 *
 * `{admin, rrhh}`. El Anexo B lo fija con una palabra —«rol rrhh»— y aqui se
 * escribe con las dos consecuencias que tiene: el `responsable_departamento`
 * **no entra** —el §7.3 ni siquiera le da `reports:*`, asi que se queda en el
 * middleware— y el `auditor` tampoco, porque lo suyo es `reports:legal` y el
 * registro normalizado para un requerimiento (RF-IN-05), no un fichero preparado
 * para cargar en el programa de nomina del hotel.
 *
 * ## Por que es policy propia y no la del informe por periodo
 *
 * Hoy dicen lo mismo, y aun asi son dos. `PeriodReportPolicy` responde «¿quien
 * puede ver el cuadro de horas?» y esta «¿quien puede sacar el fichero con el que
 * se paga?». El dia que el producto decida que un responsable ve las horas de su
 * equipo —el propio docblock de aquella lo anticipa— esa concesion **no puede**
 * arrastrar consigo la salida a nomina, y con una sola policy la arrastraria sin
 * que nadie lo decidiera. Separarlas es lo que obliga a tomar las dos decisiones
 * por separado.
 *
 * ## La policy es la mitad de la autorizacion
 *
 * La otra es el ambito `reports:*`, que el middleware `ability` verifica antes, y
 * la tercera —que no es autorizacion sino licencia— es `Feature::PayrollExport`,
 * que responde `402` y no `403`: no tener contratada la exportacion para nomina y
 * no tener permiso para pedirla son dos cosas distintas y el cliente merece
 * distinguirlas.
 *
 * **Se registra contra {@see PayrollLayout}**, que es un objeto de valor y no un
 * modelo Eloquent, igual que las hermanas de este directorio: asi la autorizacion
 * se decide **antes** de tocar la base de datos. Se usa la plantilla y no el
 * informe porque es el tipo propio de esta salida; registrar las dos policies
 * contra `PeriodReport` habria obligado a distinguirlas por el nombre de la
 * habilidad, que es justo lo que hace que dos autorizaciones se confundan.
 */
final class PayrollExportPolicy
{
    /**
     * Roles que pueden sacar el fichero de nomina.
     *
     * Metodo y no constante, por lo mismo que en las hermanas: el conjunto puede
     * cambiar, y lo que no cambia —el alcance— se resuelve, no se enumera.
     *
     * @return list<UserRole>
     */
    private static function readers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    /** `GET /api/v1/reports/payroll-export`. */
    public function export(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::readers());
    }
}
