<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\PlanUsageCounter;
use App\Modules\Product\Application\Port\TelemetryCounters;
use App\Modules\Product\Application\Port\TelemetryFacts;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Product\Domain\ValueObject\TelemetryReport;
use App\Modules\Product\Domain\ValueObject\TelemetryScaleBand;
use App\Modules\Product\Domain\ValueObject\TelemetryState;
use App\Modules\Product\Domain\ValueObject\TelemetryUsage;
use App\Modules\Shared\Application\Port\Clock;
use Throwable;

/**
 * Construye el documento de telemetria (**RF-PD-12**, ficha 5.10 punto 8).
 *
 * ## No decide si se envia
 *
 * Eso es de {@see SendTelemetryHandler}, que comprueba las tres condiciones
 * **antes** de llamar aqui. Esta separacion es lo que permite que
 * `php artisan product:telemetry` —sin `--send`— enseñe el documento exacto que
 * se enviaria aunque la telemetria este apagada: la ficha lo pide con esas
 * palabras, *para que el cliente decida con el delante*.
 *
 * ## Reutiliza lo que ya existe y toca la base de datos lo justo
 *
 * La licencia sale del punto unico de resolucion (`GetLicenseStatusHandler`), el
 * veredicto de cada comprobacion sale de `product:doctor` tal cual, la plantilla
 * y los quioscos activos salen de {@see PlanUsageCounter} —el contador de
 * ADR-028, ya escrito y ya probado— y los contadores de uso salen de las series
 * de Redis del doc 02 §8.2. Lo unico que se consulta de nuevo son dos `count(*)`
 * y un `SHOW`, en {@see TelemetryFacts}.
 *
 * ## Ninguna fuente puede tumbar el informe
 *
 * Misma regla que el paquete de diagnostico y que `doctor`: lo que no se puede
 * leer queda como `null` o como el tramo `0`, nunca como una excepcion. Una
 * telemetria que reventara dejaria un `error` semanal en el log de una
 * instalacion sana.
 *
 * ## `usage_7d` es una RESTA
 *
 * Las series de Redis son acumuladas desde la instalacion. Lo que viaja es la
 * diferencia con el acumulado del ultimo envio **correcto**, que es lo que
 * {@see TelemetryState} guarda. En el primer envio no hay con que restar y los
 * cuatro contadores van a `null`: la ficha prohibe inventar. La quinta cifra
 * —incidencias abiertas— es un nivel y va tal cual.
 */
final readonly class BuildTelemetryReportHandler
{
    public function __construct(
        private GetLicenseStatusHandler $licenses,
        private RunDoctorHandler $doctor,
        private PlanUsageCounter $usage,
        private TelemetryCounters $counters,
        private TelemetryFacts $facts,
        private Clock $clock,
        private string $productVersion,
        private string $phpVersion,
        private string $doctorLocale,
    ) {}

    public function handle(TelemetryState $state): TelemetryDraft
    {
        $snapshot = $this->snapshot();
        $status = $this->licenseStatus();
        $license = $status['license'];

        $report = new TelemetryReport(
            installationId: $state->installationId,
            sentAt: $this->clock->now(),
            productVersion: $this->productVersion,
            phpVersion: $this->phpVersion,
            databaseVersion: $this->guard(fn (): ?string => $this->facts->databaseVersion()),
            licenseState: $status['state'],
            licensePlan: $license instanceof License ? $license->plan : null,
            licenseFeatures: $license instanceof License ? $license->featureNames() : [],
            daysUntilExpiry: $status['days_until_expiry'],
            employeesActive: TelemetryScaleBand::of(
                $this->guard(fn (): int => $this->usage->count(PlanLimit::Employees)) ?? 0,
            ),
            devicesActive: $this->guard(fn (): int => $this->usage->count(PlanLimit::Devices)) ?? 0,
            departments: $this->guard(fn (): int => $this->facts->departments()) ?? 0,
            usage: new TelemetryUsage(
                scansAccepted: TelemetryUsage::delta($snapshot, $state->counters, 'scans_accepted'),
                scansRejected: TelemetryUsage::delta($snapshot, $state->counters, 'scans_rejected'),
                batchesSynced: TelemetryUsage::delta($snapshot, $state->counters, 'batches_synced'),
                incidentsOpen: $this->guard(fn (): ?int => $this->facts->openIncidents()),
                reportsGenerated: TelemetryUsage::delta($snapshot, $state->counters, 'reports_generated'),
            ),
            doctor: $this->doctorVerdicts(),
        );

        return new TelemetryDraft($report, $snapshot);
    }

    /**
     * El estado de la licencia, ya reducido a lo que viaja.
     *
     * Sin licencia no se lanza: «sin licencia» es un estado (regla dura 15) y una
     * instalacion recien montada tiene que poder enviar telemetria igual — de
     * hecho es cuando mas util es, porque dice que el producto arranco.
     *
     * @return array{state: string, license: License|null, days_until_expiry: int|null}
     */
    private function licenseStatus(): array
    {
        try {
            $status = $this->licenses->handle();

            return [
                'state' => $status->state->value,
                'license' => $status->license,
                'days_until_expiry' => $status->daysUntilExpiry(),
            ];
        } catch (Throwable) {
            return ['state' => 'unverifiable', 'license' => null, 'days_until_expiry' => null];
        }
    }

    /**
     * Solo el veredicto de cada comprobacion.
     *
     * `summary`, `fix` y `details` se quedan fuera a proposito: llevan rutas del
     * servidor, hosts de correo y nombres de fichero. Eso viaja en el paquete de
     * diagnostico, que el cliente decide enviar y puede abrir antes.
     *
     * El idioma es el del informe de soporte y no el de la instalacion, por lo
     * mismo que en el paquete: aqui ademas da igual, porque no sale ni un texto.
     *
     * @return array<string, string>
     */
    private function doctorVerdicts(): array
    {
        try {
            $report = $this->doctor->handle($this->doctorLocale);
        } catch (Throwable) {
            return [];
        }

        $verdicts = [];

        foreach ($report->checks as $check) {
            /** @var DoctorCheck $check */
            $verdicts[$check->id] = $check->status->value;
        }

        ksort($verdicts, SORT_STRING);

        return $verdicts;
    }

    /**
     * @return array<string, int>
     */
    private function snapshot(): array
    {
        try {
            return $this->counters->snapshot();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function guard(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }
}
