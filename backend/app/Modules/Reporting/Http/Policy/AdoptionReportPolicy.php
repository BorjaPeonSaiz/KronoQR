<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Policy;

use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver el cuadro de impacto y adopcion (**RF-IN-08**, Anexo B del doc
 * 01, regla dura 18).
 *
 * ## `{admin, rrhh}` y **nadie mas**
 *
 * El Anexo B lo dice literal: `GET /api/v1/reports/adoption [rol: admin|rrhh]`. Es
 * el conjunto mas estrecho de las policies de este modulo, mas que la vista de
 * cumplimiento y que la bandeja de incidencias, y la razon no es que el cuadro sea
 * mas sensible —es agregado y no lleva ni un nombre— sino que es **de otra
 * naturaleza**: mide si el sistema esta sirviendo, que es una pregunta de
 * direccion y de renovacion de licencia, no de gestion del turno.
 *
 * ## El `responsable_departamento` no entra, y la alternativa seria peor
 *
 * Podria pensarse en dejarle entrar acotado a su departamento, como en la
 * presencia en vivo y en el cumplimiento. **No cabe**, y no por permisos: un
 * cuadro de adopcion de un departamento de tres personas convierte «horas
 * trabajadas» y «correcciones» en el dato de esas tres, servido en una pantalla
 * cuya finalidad es medir la adopcion del sistema (regla dura 21, decision 12 de
 * la ficha 3.13). El cuadro es de la instalacion entera precisamente para que eso
 * no pueda pasar, y ese es el motivo por el que su consulta no lleva alcance.
 *
 * Ademas se queda en el middleware antes de llegar aqui: el §7.3 no le da
 * `reports:*`.
 *
 * ## El `auditor` tampoco, teniendo un ambito de informes
 *
 * Lleva `reports:legal` —el estrecho, que abre la exportacion para la Inspeccion—
 * y no la familia. Es coherente con el resto del modulo: **auditar es mirar lo que
 * quedo escrito**, y este cuadro no es registro, es una medida de como va el
 * producto.
 *
 * ## La policy es la mitad de la autorizacion
 *
 * La otra es el ambito `reports:*`, que el middleware `ability` verifica antes. Un
 * token de quiosco o de portal no llega aqui aunque su portador tuviera rol —no
 * tiene el ambito— y una cuenta con el ambito pero sin rol no ve nada.
 *
 * **Se registra contra {@see AdoptionReport}, que es un objeto de dominio y no un
 * modelo Eloquent**, igual que la vista de cumplimiento: asi la autorizacion se
 * decide **antes** de tocar la base de datos.
 *
 * Escrito en prosa y no con `{@see}` a las hermanas porque Pint resolveria la
 * referencia a un `use`, y un `use` de otro modulo es la frontera del §1.6 que
 * Deptrac rechaza. Las de este directorio son `PeriodReportPolicy`,
 * `PayrollExportPolicy` y `ComplianceSummaryPolicy`.
 */
final class AdoptionReportPolicy
{
    /**
     * Roles que pueden ver el cuadro, que es «rrhh+».
     *
     * Metodo y no constante por lo mismo que en las hermanas: el conjunto puede
     * cambiar, y una constante publica invitaria a leerlo desde fuera en lugar de
     * preguntar a la policy.
     *
     * @return list<UserRole>
     */
    private static function watchers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    /** `GET /api/v1/reports/adoption`. */
    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::watchers());
    }

    /**
     * `GET /api/v1/reports/adoption/export`.
     *
     * **Metodo propio aunque hoy diga exactamente lo mismo que `view()`**, por el
     * mismo criterio que `ReportExportPolicy::request()` y `requestPayroll()`: lo
     * que sale por aqui es un fichero que se reenvia por correo y que **deja
     * asiento en `audit_log`**, y el dia que alguien quisiera dejar ver el cuadro
     * en pantalla sin poder llevarselo, esa concesion tiene que poder hacerse sin
     * tocar la otra. Con un solo metodo, el cambio seria invisible desde aqui.
     */
    public function export(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::watchers());
    }
}
