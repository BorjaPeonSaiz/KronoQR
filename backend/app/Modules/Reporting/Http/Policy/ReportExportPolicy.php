<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Policy;

use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede pedir, ver y descargar un informe generado en diferido
 * (**RF-IN-06**, **RF-IN-07**, Anexo B del doc 01, regla dura 18).
 *
 * ## `request` es exactamente quien puede pedir el informe sincrono
 *
 * `{admin, rrhh}`, el mismo conjunto que {@see PeriodReportPolicy}. Tiene que
 * serlo: lo que sale en diferido es **el mismo informe** que sale en pantalla,
 * con el agravante de que aqui queda un fichero en el disco. Un camino de
 * generacion con la autorizacion mas floja que su consulta es la forma habitual
 * de que la autorizacion no sirva de nada.
 *
 * **El `responsable_departamento` no entra, y no es un olvido.** El §7.3 no le da
 * `reports:*` —lleva `attendance:read`, `attendance:correct`, `incidents:*` y
 * `employees:read`—, asi que ni siquiera pasa del middleware. Que la policy diga
 * lo mismo que el ambito es deliberado: si dijeran cosas distintas, una de las
 * dos estaria de adorno. La ficha 3.9 describe este conjunto como «manager+»
 * siguiendo al Anexo B; el conjunto real que «manager+» designa **para los
 * informes** es este, y asi lo dejo escrito la tarea 2.8.
 *
 * El alcance por departamento sigue entrando en la consulta y viaja congelado en
 * la fila (RF-ID-03), preparado para el dia en que el producto decida que un
 * responsable ve las horas de su equipo: ese dia se le añade el ambito y el rol,
 * y la consulta ya esta acotada.
 *
 * ## `requestPayroll` es `rrhh+`
 *
 * `{admin, rrhh}` tambien, y aqui la coincidencia **no es casualidad sino el
 * enunciado del Anexo B**: la salida a nomina es «rol rrhh». Se declara aparte y
 * no como el mismo metodo porque son dos potestades distintas que hoy recaen en
 * las mismas personas: el dia que un `responsable_departamento` reciba
 * `reports:*` para ver las horas de su equipo —que es a donde apunta RF-ID-03—,
 * ese rol entrara en `request` y **no** en `requestPayroll`, y la separacion ya
 * estara hecha. Con un solo metodo, ese dia habria que acordarse.
 *
 * ## `view` y `download` no miran el rol: miran el dueño
 *
 * Decision 2 de la ficha. **Solo el solicitante ve y descarga su exportacion,
 * tambien si quien pregunta es `admin`.** Un informe en diferido contiene las
 * horas nominales de un conjunto de personas que eligio quien lo pidio; que un
 * administrador pueda pedir enlace de los ficheros que genero RRHH seria una via
 * de acceso a datos personales que nadie ha autorizado.
 *
 * Y la denegacion es `404`, no `403`: un `403` confirmaria que esa exportacion
 * existe. Por eso la comprobacion **no vive aqui sino en la consulta**
 * (`findByUuidFor()`), que es donde puede devolver «no hay nada» en lugar de «no
 * puedes». Estos dos metodos son la puerta previa —que quien pregunta sea de los
 * roles que pueden tener exportaciones— y existen para que la autorizacion
 * negativa pruebe cada endpoint por separado.
 *
 * ## El fabricante nunca
 *
 * Regla dura 16, ADR-020. Una concesion de soporte actua como su rol ante las
 * policies, y estas rutas reparten horas de la plantilla del cliente. No hay
 * ningun escenario de diagnostico que exija generar el informe de nomina de un
 * hotel.
 *
 * ## La licencia no se comprueba aqui
 *
 * Se comprueba en el controlador, al pedir (decision 6): `Feature::AdvancedReports`
 * para `period` y `Feature::PayrollExport` para `payroll`. Una policy que
 * consultara la licencia devolveria `403` donde el contrato promete `402`, y
 * quien lo recibiera creeria que le falta un permiso cuando lo que falta es una
 * renovacion.
 *
 * **Se registra contra {@see ReportExport}, que es un objeto de dominio y no un
 * modelo Eloquent**, igual que las hermanas de este directorio: asi la
 * autorizacion se decide **antes** de tocar la base de datos.
 *
 * ## Desde la 2.3.0, dos clases mas y el auditor (V5-PA-4)
 *
 * - `requestLegal` — la exportacion para la Inspeccion en diferido: el mismo
 *   conjunto que `LegalExportPolicy::generate()` de la sincrona,
 *   `{admin, rrhh, auditor}`. Sin el `auditor`, el rol cuya funcion es
 *   precisamente esta seguiria colgado de una descarga de 60 s.
 * - `requestAdoption` — el cuadro de impacto: `{admin, rrhh}`, el de
 *   `AdoptionReportPolicy::export()`.
 * - `view`, `download` y `cancel` admiten ademas al `auditor`, que solo puede
 *   tener exportaciones `legal`: la consulta filtra por dueño, asi que no ve
 *   ninguna otra.
 *
 * **El fabricante sigue sin entrar en ninguna**, tampoco en la legal en
 * diferido (decision del propietario del 22-10-2026, ADR-020): esta familia de
 * rutas deja ficheros con la plantilla en el disco del cliente durante dias.
 * Ningun alcance de soporte concede `reports:legal` ni `reports:*`
 * (`SupportScope`), asi que el middleware ya lo para; este `false` es la segunda
 * mitad, por si un alcance futuro los concediera.
 *
 * **El ambito del token se comprueba ademas del rol** (doc 02 §7.3): las
 * rutas aceptan `reports:legal` o `reports:*`, y `RequestReportExportRequest`
 * exige `reports:*` para toda clase que no sea `legal`.
 */
final class ReportExportPolicy
{
    /** `POST /api/v1/reports/exports` con `kind: period`. */
    public function request(ManagementActor $actor): bool
    {
        return self::isReportReader($actor);
    }

    /** `POST /api/v1/reports/exports` con `kind: payroll` (Anexo B: rol `rrhh`). */
    public function requestPayroll(ManagementActor $actor): bool
    {
        return self::isPayrollReader($actor);
    }

    /** `POST /api/v1/reports/exports` con `kind: legal` (RF-IN-05). */
    public function requestLegal(ManagementActor $actor): bool
    {
        return self::isOwnerRole($actor);
    }

    /** `POST /api/v1/reports/exports` con `kind: adoption` (RF-IN-08). */
    public function requestAdoption(ManagementActor $actor): bool
    {
        return self::isReportReader($actor);
    }

    /** `GET /api/v1/reports/exports` y `GET /api/v1/reports/exports/{uuid}`. */
    public function view(ManagementActor $actor): bool
    {
        return self::isOwnerRole($actor);
    }

    /**
     * La emision del enlace de descarga.
     *
     * **No autoriza la descarga en si**: aquella va sin sesion y la autoriza el
     * token de un solo uso (ADR-041). Lo que autoriza este metodo es **pedir un
     * enlace**, que es lo que hace `GET /reports/exports/{uuid}`.
     */
    public function download(ManagementActor $actor): bool
    {
        return self::isOwnerRole($actor);
    }

    /**
     * `POST /api/v1/reports/exports/{uuid}/cancel` (V5-PA-4).
     *
     * Como `view`: la puerta previa de rol. Que la exportacion sea **suya** lo
     * decide la consulta, y la ajena responde `404`.
     */
    public function cancel(ManagementActor $actor): bool
    {
        return self::isOwnerRole($actor);
    }

    /**
     * Los roles que pueden tener alguna exportacion en diferido: los de los
     * informes y el `auditor`, que solo puede tener las `legal`.
     *
     * @return list<UserRole>
     */
    private static function owners(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH, UserRole::AUDITOR];
    }

    private static function isOwnerRole(ManagementActor $actor): bool
    {
        if ($actor->isSupportActor()) {
            return false;
        }

        return $actor->actsAs(...self::owners());
    }

    /**
     * Roles que pueden pedir informes de horas de terceros.
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

    /**
     * Roles que pueden sacar la nomina (Anexo B del doc 01).
     *
     * @return list<UserRole>
     */
    private static function payrollReaders(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    private static function isReportReader(ManagementActor $actor): bool
    {
        if ($actor->isSupportActor()) {
            return false;
        }

        return $actor->actsAs(...self::readers());
    }

    private static function isPayrollReader(ManagementActor $actor): bool
    {
        if ($actor->isSupportActor()) {
            return false;
        }

        return $actor->actsAs(...self::payrollReaders());
    }
}
