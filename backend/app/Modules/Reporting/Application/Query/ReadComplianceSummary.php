<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Query;

use App\Modules\Reporting\Application\Port\ComplianceFactsReader;
use App\Modules\Reporting\Application\Port\ComplianceIncidentLinks;
use App\Modules\Reporting\Application\Port\ComplianceProfileReference;
use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\Policy\ComplianceEvaluation;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFacts;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFinding;
use App\Modules\Reporting\Domain\ValueObject\ComplianceProfileRef;
use App\Modules\Reporting\Domain\ValueObject\ComplianceSummary;
use App\Modules\Reporting\Domain\ValueObject\ComplianceSummaryQuery;
use App\Modules\Reporting\Domain\ValueObject\ComplianceTotals;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Domain\ValueObject\ComplianceRuleSuspension;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use DateTimeZone;
use RuntimeException;

/**
 * La vista de cumplimiento que pinta el panel (**RF-PA-06**).
 *
 * ## Quien decide que, en una sola pantalla
 *
 * - **Los umbrales** los resuelve `CompliancePolicyProvider` desde el perfil del
 *   centro (regla dura 14, ADR-017): ni un literal `12` en este camino. Cambiar
 *   `min_rest_hours` en el panel altera lo que sale de aqui sin tocar una linea.
 * - **La aritmetica** la hace {@see ComplianceEvaluation}, en el dominio y sin
 *   reloj.
 * - **El instante** sale del puerto `Clock` (regla dura 2) y **la zona** del
 *   centro de la instalacion (ADR-040, regla dura 3): el cliente no adivina
 *   ninguno de los dos.
 * - **Los criterios** viajan como claves y los traduce el `Resource`.
 *
 * ## Solo lee, y aun asi deja constancia
 *
 * Lo que sale de aqui es una lista de personas con nombre, departamento y en que
 * han incumplido: datos personales de terceros, y RS-05 no admite matices. El
 * asiento describe **el alcance** —periodo, filtros, cuantas filas, con que
 * ambito— y jamas lo divulgado (regla dura 21).
 *
 * **Y no se agrupa**, al contrario que la presencia en vivo: esta pantalla no se
 * sondea —se abre, se mira y se cierra— asi que cada apertura es un acceso real
 * que merece su fila. Agruparla escondería justo el patron que una inspeccion
 * busca: alguien revisando el cumplimiento de una sola persona varias veces.
 *
 * ## El rango por omision son 28 dias
 *
 * Cuatro semanas terminadas hoy **en la zona del centro**, que es la unica que
 * decide que dia es hoy para esa plantilla: a las 00:30 de Madrid, la zona del
 * servidor dejaria fuera la jornada en curso justo en el turno de noche. Lo
 * resuelve este caso de uso y no el `FormRequest`, que no sabe donde esta el
 * hotel.
 */
final readonly class ReadComplianceSummary
{
    /** Vocabulario estable del `audit_log`, en ingles y sin datos dentro. */
    private const string DATASET = 'compliance_summary';

    /** Cuatro semanas, la ventana que RRHH revisa cuando no escribe fechas. */
    public const int DEFAULT_DAYS = 28;

    /**
     * Los criterios de evaluacion, como claves de `lang/*\/compliance-summary.php`.
     *
     * Van en la respuesta y no en un manual por lo mismo que en el informe por
     * periodo: un aviso cuyo criterio no se ve es un aviso que nadie defiende ante
     * un empleado (paso 5 de la ficha).
     */
    private const array BASE_CRITERIA = [
        'criteria.rest_between_workdays',
        'criteria.daily_total',
        'criteria.week',
        'criteria.open_shift',
        'criteria.scope',
    ];

    public function __construct(
        private ComplianceFactsReader $facts,
        private ComplianceProfileReference $profiles,
        private ComplianceIncidentLinks $incidents,
        private CompliancePolicyProvider $policies,
        private InstallationSiteProvider $installation,
        private Clock $clock,
        private PersonalDataAccessLog $disclosures,
        /**
         * De donde sale si RN-12 se evalua en esta instalacion (RF-AT-12,
         * decision 8 de la ficha 3.5).
         *
         * El evaluador es dominio puro y no consulta configuracion (regla dura
         * 14): recibe la suspension ya construida. La vista **recalcula siempre
         * con lo vigente**, asi que activar `ATTENDANCE_BREAK_CLOCKING` hace que
         * la siguiente consulta empiece a contar los avisos de pausa sin que
         * nadie reprocese nada.
         */
        private OperationalSettingsProvider $settings,
    ) {}

    /**
     * @param  int  $maxRangeDays  Techo del rango sincrono, de `config/reporting.php`.
     *
     * @throws InstallationSiteMissing antes de la puesta en marcha, cuando no hay centro
     *                                 del que tomar la zona horaria (RF-PD-03)
     * @throws ReportTooLargeForSynchronousDelivery cuando el rango pedido supera el techo
     *                                              sincrono de la instalacion (`422`)
     */
    public function handle(ComplianceSummaryCriteria $criteria, int $maxRangeDays): ComplianceSummary
    {
        $site = $this->installation->installationSite();

        if (! $site instanceof InstallationSite) {
            // Sin centro no hay zona, y sin zona ni «hoy» ni las jornadas
            // significan nada. Es un estado de la instalacion, no un error de
            // quien pregunta: `409` (ver bootstrap/app.php).
            throw new InstallationSiteMissing;
        }

        $profile = $this->profiles->forSite($site->id);

        if (! $profile instanceof ComplianceProfileRef) {
            // El mismo estado imposible que describe `DbCompliancePolicyProvider`:
            // ni perfil asignado ni perfil por defecto. Solo se alcanza editando
            // la tabla a mano, y no se puede servir una vista de cumplimiento sin
            // decir con que criterio se ha medido.
            throw new RuntimeException(
                'No hay perfil de cumplimiento para el centro '.$site->id.' ni perfil por defecto en la '
                .'instalacion: la vista de cumplimiento no puede enseñar el criterio con el que evalua.'
            );
        }

        $policy = $this->policies->forSite($site->id);
        $evaluation = new ComplianceEvaluation(
            $policy,
            ComplianceRuleSuspension::forInstallation($this->settings->forSite($site->id)->breakClockingEnabled),
        );
        $query = $this->resolve($criteria, $site->timezone);

        // **Antes de tocar la base de datos**, que es lo barato. Se comprueba aqui
        // y no en el `FormRequest` porque el rango puede venir a medias: con solo
        // `from`, el `to` lo pone «hoy en la zona del centro» y el borde no sabe
        // donde esta el hotel. Un techo que solo mirase el par completo dejaria
        // pasar `?from=2020-01-01` sin mas.
        if ($query->range->days() > $maxRangeDays) {
            throw ReportTooLargeForSynchronousDelivery::complianceRangeTooWide($query->range->days(), $maxRangeDays);
        }

        $facts = $this->facts->factsFor($query, $policy->weekStartsOn);

        // **Todo lo del periodo primero, y el filtro despues.** Los recuentos
        // describen el periodo entero —las cuatro tarjetas del panel son la foto
        // completa— y `data` es lo que se ha pedido ver. Calcular los totales
        // sobre la lista ya filtrada haria que pulsar «solo descansos» pusiera a
        // cero las otras tres tarjetas, y quien lo hiciera creeria que ha dejado
        // de haber jornadas excesivas.
        $all = $evaluation->evaluate($facts, $query->range);

        $findings = $this->linked($this->filtered($all, $query));

        $summary = new ComplianceSummary(
            findings: $findings,
            generatedAt: $this->clock->now(),
            timeZone: $site->timezone,
            range: $query->range,
            profile: $profile,
            weekStartsOn: $policy->weekStartsOn,
            rules: $evaluation->appliedRules(),
            totals: ComplianceTotals::of($all, $this->employeesEvaluated($facts)),
            unrestrictedScope: $query->scope->isUnrestricted(),
            criteria: self::BASE_CRITERIA,
        );

        $this->recordDisclosure($query, $summary);

        return $summary;
    }

    /**
     * El rango pedido, con la omision ya resuelta **en la zona del centro**.
     *
     * Veintiocho dias: cuatro semanas, la ventana que RRHH revisa. No es un techo
     * —ese es `REPORTING_COMPLIANCE_MAX_RANGE_DAYS`, que comprueba el borde— sino
     * la pregunta que trae quien abre la pantalla sin escribir fechas.
     */
    private function resolve(ComplianceSummaryCriteria $criteria, string $timeZone): ComplianceSummaryQuery
    {
        $to = $criteria->to ?? $this->clock->now()->setTimezone(new DateTimeZone($timeZone))->format('Y-m-d');

        return new ComplianceSummaryQuery(
            scope: $criteria->scope,
            range: $criteria->from === null
                ? DateRange::endingOn($to, self::DEFAULT_DAYS)
                : DateRange::between($criteria->from, $to),
            departmentId: $criteria->departmentId,
            employeeUuid: $criteria->employeeUuid,
            rule: $criteria->rule,
        );
    }

    /**
     * El filtro `rule`, aplicado **despues** de evaluar.
     *
     * Evaluarlo todo y quedarse con una regla cuesta lo mismo —los hechos ya estan
     * cargados— y evita el fallo silencioso de que filtrar cambie algo mas: si el
     * filtro entrara en la consulta, la jornada anterior que RN-10 necesita podria
     * dejar de cargarse al pedir solo RN-11.
     *
     * @param  list<ComplianceFinding>  $findings
     * @return list<ComplianceFinding>
     */
    private function filtered(array $findings, ComplianceSummaryQuery $query): array
    {
        if ($query->rule === null) {
            return $findings;
        }

        return array_values(array_filter(
            $findings,
            static fn (ComplianceFinding $finding): bool => $finding->rule === $query->rule,
        ));
    }

    /**
     * Engancha a cada hallazgo la incidencia de la bandeja que describe el mismo
     * hecho, si existe.
     *
     * Una sola consulta por lote para todos: ver el docblock del puerto.
     *
     * @param  list<ComplianceFinding>  $findings
     * @return list<ComplianceFinding>
     */
    private function linked(array $findings): array
    {
        $keys = [];

        foreach ($findings as $finding) {
            $type = $finding->rule->incidentType();

            if ($type === null || $finding->workDate === null) {
                continue;
            }

            $keys[] = [
                'employee_uuid' => $finding->employee->uuid,
                'work_date' => $finding->workDate,
                'type' => $type,
            ];
        }

        if ($keys === []) {
            return $findings;
        }

        $links = $this->incidents->linksFor($keys);

        return array_map(
            static function (ComplianceFinding $finding) use ($links): ComplianceFinding {
                $type = $finding->rule->incidentType();

                if ($type === null || $finding->workDate === null) {
                    return $finding;
                }

                return $finding->linkedTo(
                    $links[$finding->employee->uuid.'|'.$finding->workDate.'|'.$type] ?? null,
                );
            },
            $findings,
        );
    }

    /**
     * Personas del alcance con **alguna jornada evaluada**, que es el denominador
     * honesto: quien no ficho no ha cumplido ni incumplido.
     *
     * ## Se cuenta sobre la ventana EVALUADA, no sobre el rango pedido
     *
     * Y la diferencia no es teorica. Las semanas del borde se evaluan enteras
     * (decision 6), asi que una persona puede recibir un hallazgo de RN-17 por una
     * semana cuyas siete jornadas caen **fuera** de `[from, to]` —basta pedir un
     * jueves en el que no ficho nadie—. Contando solo las jornadas de dentro del
     * rango, esa persona salia en `employees_affected` y no en
     * `employees_evaluated`, y el panel enseñaba «1 de 0»: un ratio imposible que
     * hace dudar de toda la pantalla.
     *
     * El precio asumido es el simetrico y mucho mas leve: alguien que solo ficho
     * en el arrastre de la semana del borde entra en el denominador. Es coherente
     * —se le ha evaluado— y no produce ninguna cifra imposible.
     *
     * @param  list<ComplianceFacts>  $facts  las jornadas que el evaluador ha visto
     */
    private function employeesEvaluated(array $facts): int
    {
        $seen = [];

        foreach ($facts as $day) {
            $seen[$day->employee->uuid] = true;
        }

        return \count($seen);
    }

    /**
     * RS-05. **Antes de devolver, no despues**: si la escritura de auditoria
     * falla, la divulgacion no ocurre (regla dura 6, ADR-027).
     */
    private function recordDisclosure(ComplianceSummaryQuery $query, ComplianceSummary $summary): void
    {
        $this->disclosures->recordDisclosure(self::DATASET, $summary->findingCount(), [
            'from' => $query->range->isoFrom(),
            'to' => $query->range->isoTo(),
            ...($query->departmentId === null ? [] : ['department_id' => $query->departmentId]),
            // **El uuid del empleado NO se guarda, solo si lo hubo.** Aqui la
            // tentacion es fuerte —seria util saber a quien se miro— y aun asi no
            // entra: el asiento describe el alcance del acceso, y el detalle de
            // quien fue el sujeto se responde desde el propio registro de esa
            // persona. Mismo criterio que el termino de busqueda del panel.
            'employee' => $query->employeeUuid !== null,
            ...($query->rule === null ? [] : ['rule' => $query->rule->value]),
            // Distingue «RRHH reviso el hotel entero» de «un responsable reviso su
            // cocina», que ante una brecha (RL-15) no es lo mismo.
            'scope' => $summary->scopeName(),
        ]);
    }
}
