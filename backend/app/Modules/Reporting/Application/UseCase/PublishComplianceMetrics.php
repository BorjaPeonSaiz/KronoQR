<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\ComplianceMetrics;
use App\Modules\Reporting\Application\Query\ComplianceSummaryCriteria;
use App\Modules\Reporting\Application\Query\ReadComplianceSummary;
use App\Modules\Reporting\Domain\ValueObject\ComplianceWeek;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use DateTimeZone;

/**
 * Publica las dos metricas de cumplimiento del cuadro «Negocio» (doc 02 §8.2,
 * paso 7 y decision 10 de la ficha 3.4).
 *
 * ```
 * compliance_findings_last_week{rule}        gauge
 * compliance_employees_affected_last_week    gauge
 * ```
 *
 * ## La ultima semana COMPLETA del perfil, no los ultimos siete dias
 *
 * Una ventana movil de siete dias daria un numero que cambia cada noche por la
 * salida del dia mas antiguo, y sobre todo mediria semanas que no existen: RN-17
 * se evalua sobre la semana del perfil, que empieza en `week_starts_on`. Un gauge
 * que contara «semanas por encima del limite» sobre una ventana desalineada
 * contaria semanas distintas de las que la pantalla enseña.
 *
 * **Completa**, ademas: la semana en curso todavia puede crecer, y publicarla
 * daria una serie que sube a lo largo de la semana y se desploma el lunes sin que
 * haya pasado nada.
 *
 * ## La MISMA consulta que la pantalla
 *
 * Con el alcance sin restringir, porque esto lo produce el servidor y no una
 * cuenta. Escribir una consulta propia «mas barata» para la metrica seria una
 * segunda definicion de lo que es un incumplimiento, y el dia que discreparan,
 * Grafana diria una cosa y el panel otra delante del mismo cliente.
 *
 * **Consecuencia asumida:** esto escribe tambien el asiento de divulgacion de
 * RS-05 que escribe la consulta, con actor «sistema». Es correcto y no ruido: el
 * proceso ha leido el cumplimiento de toda la plantilla, y que eso quede escrito
 * una vez al dia es exactamente lo que RL-15 quiere ver.
 *
 * ## Sin centro no hay metrica
 *
 * Antes de la puesta en marcha (RF-PD-03) no hay centro con el que resolver el
 * perfil ni la zona. Se devuelve `false` y quien llama lo dice por su salida.
 */
final readonly class PublishComplianceMetrics
{
    public function __construct(
        private ReadComplianceSummary $summaries,
        private ComplianceMetrics $metrics,
        private CompliancePolicyProvider $policies,
        private InstallationSiteProvider $installation,
        private Clock $clock,
    ) {}

    /**
     * @return bool `false` si todavia no hay centro de trabajo.
     */
    public function handle(): bool
    {
        $site = $this->installation->installationSite();

        if (! $site instanceof InstallationSite) {
            return false;
        }

        $week = $this->lastCompleteWeek($site);

        $summary = $this->summaries->handle(
            new ComplianceSummaryCriteria(
                // Sin restringir: es la instalacion la que se mide, no un
                // departamento. Un recuento acotado no seria la metrica del centro.
                scope: AccessScope::unrestricted(),
                from: $week->startsOn,
                to: $week->endsOn,
            ),
            // Siete dias: el techo sincrono de la instalacion no pinta nada aqui
            // —esto no atiende a nadie— y pasarlo obligaria a que `Application`
            // leyera configuracion (doc 02 §3.5). El rango es fijo por
            // construccion, asi que el techo es exactamente el que se pide.
            maxRangeDays: 7,
        );

        $this->metrics->publish(
            findingsByRule: $summary->totals->byRule,
            employeesAffected: $summary->totals->employeesAffected,
            weekStartsOn: $week->startsOn,
            at: $this->clock->now(),
        );

        return true;
    }

    /**
     * La ultima semana del perfil que ya termino, en la zona del centro.
     *
     * Se calcula desde **ayer** y no desde hoy: con la semana de hoy se publicaria
     * una semana a medias los seis primeros dias, y el ultimo dia se publicaria
     * dos veces la misma. Desde ayer, el primer dia de la semana nueva publica la
     * anterior completa y los seis siguientes la repiten sin cambiarla.
     */
    private function lastCompleteWeek(InstallationSite $site): ComplianceWeek
    {
        $yesterday = $this->clock->now()
            ->setTimezone(new DateTimeZone($site->timezone))
            ->modify('-1 day')
            ->format('Y-m-d');

        $current = ComplianceWeek::containing($yesterday, $this->policies->forSite($site->id)->weekStartsOn);

        // Si ayer cae en una semana que todavia no ha terminado —lo normal— la
        // completa es la anterior; si ayer fue su ultimo dia, es esa misma.
        return $current->endsOn <= $yesterday ? $current : $current->previous();
    }
}
