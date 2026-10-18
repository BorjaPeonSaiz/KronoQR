<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use DateTimeImmutable;

/**
 * El informe de la pasada de retencion (RF-PR-03): lo que se purgaria o lo que
 * se ha purgado.
 *
 * Es el **mismo objeto** en simulacion y en ejecucion real, y a proposito: el
 * responsable aprueba un informe y lo que se ejecuta produce otro con la misma
 * forma, de modo que los dos se pueden comparar linea a linea. Un informe de
 * simulacion con otro formato que el de ejecucion no se compara: se cree.
 *
 * **Sin datos personales** (regla dura 21): ambitos, recuentos, rangos de fecha
 * y nombres de tabla. Ni un nombre, ni un `employee_uuid` siquiera —aqui no hace
 * falta ninguno—, porque este fichero se archiva y se adjunta.
 *
 * ## La frase de confirmacion
 *
 * La calcula el informe y no la consola, porque depende de **lo que se ha
 * calculado**: el corte y los anos de particion candidatos. Si el corte cambia
 * —porque paso un dia, porque alguien edito el perfil de cumplimiento— la frase
 * cambia, y la del informe de ayer deja de valer. Es la propiedad que se
 * buscaba: se ejecuta lo que se aprobo, no lo que hoy toque.
 */
final readonly class RetentionReport
{
    /**
     * @param  list<int>  $auditPartitionYears  Anos candidatos a soltarse, ascendente
     * @param  array<string, DateTimeImmutable>  $shortCycleCutoffs  Corte por ambito de ciclo corto
     * @param  list<RetentionTally>  $tallies
     * @param  list<string>  $notes  Lo que no se ha podido hacer y por que. Sin PII
     */
    public function __construct(
        public RetentionMode $mode,
        public DateTimeImmutable $generatedAt,
        public RetentionPolicySnapshot $policy,
        public DateTimeImmutable $workRecordCutoff,
        public array $auditPartitionYears,
        public array $shortCycleCutoffs,
        public array $tallies,
        public array $notes = [],
    ) {}

    /**
     * La frase que hay que escribir en `--confirm` para que la purga ocurra.
     *
     * Formato `PURGAR-<fecha de corte>-<6 hex>`. El sufijo no es seguridad
     * criptografica —quien lanza el comando ya esta dentro del servidor— sino
     * la garantia de que **no se puede teclear de memoria ni de un runbook
     * copiado**: sale de este informe y de ningun otro sitio.
     */
    public function confirmationToken(): string
    {
        $material = implode('|', [
            $this->workRecordCutoff->format('Y-m-d'),
            implode(',', $this->auditPartitionYears),
            $this->policy->legalRecordYears,
            $this->policy->technicalLogDays,
            $this->policy->errorHistoryDays,
        ]);

        return 'PURGAR-'.$this->workRecordCutoff->format('Y-m-d').'-'.substr(hash('sha256', $material), 0, 6);
    }

    /**
     * @return list<RetentionTally>
     */
    public function talliesFor(RetentionScope $scope): array
    {
        return array_values(array_filter(
            $this->tallies,
            static fn (RetentionTally $tally): bool => $tally->scope === $scope,
        ));
    }

    public function rowsFor(RetentionScope $scope): int
    {
        return array_sum(array_map(
            static fn (RetentionTally $tally): int => $tally->rows,
            $this->talliesFor($scope),
        ));
    }

    public function totalRows(): int
    {
        return array_sum(array_map(
            static fn (RetentionTally $tally): int => $tally->rows,
            $this->tallies,
        ));
    }

    public function isEmpty(): bool
    {
        return $this->totalRows() === 0;
    }

    /**
     * Recuento por tabla del ambito dado, para el `payload` del asiento y para
     * el log. `array<string, int>` y no una lista de objetos porque es lo que
     * `AuditPayload` sabe canonizar (doc 02 §7.4).
     *
     * @return array<string, int>
     */
    public function countsFor(RetentionScope $scope): array
    {
        $counts = [];

        foreach ($this->talliesFor($scope) as $tally) {
            $counts[$tally->dataset] = $tally->rows;
        }

        return $counts;
    }

    /**
     * @param  list<RetentionTally>  $tallies
     * @param  list<string>  $notes
     */
    public function executed(array $tallies, array $notes): self
    {
        return new self(
            mode: RetentionMode::Execution,
            generatedAt: $this->generatedAt,
            policy: $this->policy,
            workRecordCutoff: $this->workRecordCutoff,
            auditPartitionYears: $this->auditPartitionYears,
            shortCycleCutoffs: $this->shortCycleCutoffs,
            tallies: $tallies,
            notes: $notes,
        );
    }
}
