<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Command\RetentionRunCommand;
use App\Modules\Compliance\Application\Exception\AuditPartitionNotPurgeable;
use App\Modules\Compliance\Application\Exception\RetentionNotConfirmed;
use App\Modules\Compliance\Application\Port\AuditChainReader;
use App\Modules\Compliance\Application\Port\AuditPartitionArchive;
use App\Modules\Compliance\Application\Port\ErrorHistoryArchive;
use App\Modules\Compliance\Application\Port\RetentionMetrics;
use App\Modules\Compliance\Application\Port\RetentionReportStore;
use App\Modules\Compliance\Application\Port\TechnicalLogArchive;
use App\Modules\Compliance\Application\Port\WorkRecordArchive;
use App\Modules\Compliance\Application\Support\RetentionTelemetry;
use App\Modules\Compliance\Domain\AuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditChainAnchor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Domain\ValueObject\RetentionReport;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Aplica la retencion: propone siempre, borra solo con confirmacion (RL-02,
 * RL-11, RF-PR-03).
 *
 * ## La purga es la unica eliminacion legitima del sistema
 *
 * No es una excepcion a la regla dura 5 -«nada se borra ni se sobrescribe»-: es
 * el vencimiento del deber de conservacion. Por eso va precedida de propuesta,
 * confirmacion humana, informe y asiento, y por eso el orden de los pasos
 * importa mas que la eficiencia de cada uno.
 *
 * ## El orden, y por que es este
 *
 * 1. **Planificar** ({@see PlanRetention}), que no toca nada.
 * 2. **Comprobar la frase**. Sin ella no se sigue.
 * 3. **Verificar TODAS las particiones candidatas de `audit_log` antes de tocar
 *    nada.** Si una no verifica, se aborta la pasada entera sin haber borrado ni
 *    una fila: dejar el registro de jornada purgado y la auditoria a medias
 *    seria el peor de los desenlaces.
 * 4. **Registro de jornada**, en su transaccion y con su asiento dentro. Si el
 *    asiento falla, no se borra nada (regla dura 6).
 * 5. **Particiones de `audit_log`**, de la mas antigua a la mas nueva, con el
 *    rol de mantenimiento: sellar el ancla, `DETACH` y soltar (ADR-027). Nunca
 *    un `DELETE`.
 * 6. **Ciclo corto** -log tecnico y `error_events`, 90 dias-, que no lleva
 *    asiento propio: no tiene valor probatorio ni datos personales.
 *
 * ## Dos transacciones y dos roles, y no puede ser de otra forma
 *
 * El asiento de `audit_log` lo escribe el rol de la **aplicacion**, unico con
 * `INSERT` sobre la tabla; la particion la suelta el de **mantenimiento**, unico
 * con permiso para hacerlo y que **no** tiene `INSERT` (ADR-033). Son dos
 * sesiones distintas y no hay transaccion que las abarque sin dos fases. El
 * orden elegido -sellar y soltar primero, apuntar despues- deja el peor caso en
 * «la particion se solto y su asiento no llego», que es visible y reparable
 * porque `audit_chain_anchors` conserva el sello con su ano, sus hashes, sus
 * filas y su rol. El orden contrario dejaria un asiento afirmando una purga que
 * no ocurrio, que es indistinguible de una manipulacion.
 */
final readonly class ApplyRetention
{
    public function __construct(
        private PlanRetention $planner,
        private WorkRecordArchive $workRecords,
        private AuditPartitionArchive $partitions,
        private AuditChainReader $chain,
        private TechnicalLogArchive $technicalLog,
        private ErrorHistoryArchive $errorHistory,
        private RetentionReportStore $reports,
        private RetentionMetrics $metrics,
        private RecordAuditEntry $audit,
        private RetentionTelemetry $telemetry,
        private ConnectionInterface $connection,
        private Clock $clock,
    ) {}

    public function handle(RetentionRunCommand $command): RetentionOutcome
    {
        $now = $this->clock->now();
        $plan = $this->planner->handle($now);

        return $this->telemetry->measure(
            'compliance.apply_retention',
            [
                'retention.mode' => $command->mode->value,
                'retention.cutoff' => $plan->workRecordCutoff->format('Y-m-d'),
                'retention.candidate_rows' => $plan->totalRows(),
            ],
            fn (): RetentionOutcome => $command->isSimulation()
                ? $this->finish($plan, $now)
                : $this->execute($command, $plan, $now),
        );
    }

    private function execute(
        RetentionRunCommand $command,
        RetentionReport $plan,
        DateTimeImmutable $now,
    ): RetentionOutcome {
        $this->assertConfirmed($command, $plan);

        // Paso 3, antes de borrar nada. Si algo no cuadra sale por excepcion y la
        // instalacion se queda exactamente como estaba.
        $verified = $this->verifyPartitions($plan, $now);

        $tallies = [
            ...$this->purgeWorkRecords($command, $plan),
            ...$this->dropPartitions($verified['anchors'], $command),
            ...$this->purgeShortCycles($command, $plan),
        ];

        return $this->finish($plan->executed($tallies, $verified['notes']), $now);
    }

    private function assertConfirmed(RetentionRunCommand $command, RetentionReport $plan): void
    {
        if ($command->confirmation === null || trim($command->confirmation) === '') {
            throw RetentionNotConfirmed::withoutPhrase();
        }

        // Exacta, sin mas normalizacion que los espacios de los extremos: la
        // frase se copia del informe, no se recuerda.
        if (trim($command->confirmation) !== $plan->confirmationToken()) {
            throw RetentionNotConfirmed::withWrongPhrase();
        }
    }

    /**
     * Recorre y comprueba la cadena de cada particion candidata, en orden
     * ascendente y **encadenandolas entre si** (ADR-027).
     *
     * El `prev_hash` de la primera fila de la particion mas antigua tiene que ser
     * la genesis o el `last_hash` del ancla de una purga anterior; el de cada
     * particion siguiente, el `hash` de la ultima fila de la anterior. Sin
     * encadenarlas, purgar dos anos a la vez marcaria el segundo como huerfano.
     *
     * @return array{anchors: list<AuditChainAnchor>, notes: list<string>}
     */
    private function verifyPartitions(RetentionReport $plan, DateTimeImmutable $now): array
    {
        $anchors = [];
        $notes = [];
        $expected = null;

        foreach ($plan->auditPartitionYears as $year) {
            if (! $this->partitions->precedesEveryLiveEntry($year)) {
                throw AuditPartitionNotPurgeable::isNotAtTheHeadOfTheChain($year);
            }

            $anchor = $this->verifyPartition($year, $expected, $now);

            if ($anchor === null) {
                // Particion vacia: no hay cadena que anclar y `audit_chain_anchors`
                // exige `row_count > 0`. Se deja donde esta -una particion vacia
                // no ocupa ni estorba- en lugar de inventarle un sello.
                $notes[] = 'Particion audit_log_'.$year.' vacia: no se sella ni se suelta.';

                continue;
            }

            $anchors[] = $anchor;
            $expected = $anchor->lastHash;
        }

        return ['anchors' => $anchors, 'notes' => $notes];
    }

    /**
     * @param  string|null  $expected  Hash con el que encadena la primera fila, si ya se sabe
     */
    private function verifyPartition(int $year, ?string $expected, DateTimeImmutable $now): ?AuditChainAnchor
    {
        $rows = 0;
        $firstHash = null;
        $previous = $expected;

        foreach ($this->partitions->entriesOf($year) as $entry) {
            $rows++;

            if ($firstHash === null) {
                $previous ??= $this->chainStartFor($year, $entry->previousHash);
                $firstHash = $entry->hash;
            }

            if (! AuditChain::matches((string) $previous, $entry->previousHash)) {
                throw AuditPartitionNotPurgeable::chainIsBroken(
                    $year,
                    'el eslabon de la entrada '.($entry->id ?? 0).' no apunta a la fila anterior',
                );
            }

            if (! AuditChain::matches(AuditChain::hashFor($entry->draft, $entry->previousHash), $entry->hash)) {
                throw AuditPartitionNotPurgeable::chainIsBroken(
                    $year,
                    'el contenido de la entrada '.($entry->id ?? 0).' no produce su hash',
                );
            }

            $previous = $entry->hash;
        }

        if ($firstHash === null) {
            // Particion vacia: no hay cadena que anclar.
            return null;
        }

        return new AuditChainAnchor(
            partitionYear: $year,
            firstHash: $firstHash,
            // Si hay primera fila, el bucle corrio: `$previous` es el hash de la
            // ultima que se recorrio.
            lastHash: (string) $previous,
            rowCount: $rows,
            sealedAt: $now,
            sealedBy: $this->partitions->role(),
        );
    }

    /**
     * El arranque de la cadena para la particion mas antigua que se va a soltar:
     * la genesis, o el `last_hash` del ancla de una purga anterior. Cualquier otra
     * cosa es un hueco que nadie registro (RS-07).
     */
    private function chainStartFor(int $year, string $previousHash): string
    {
        if (AuditChain::matches(AuditChain::genesisHash(), $previousHash)) {
            return $previousHash;
        }

        if ($this->chain->anchorSealedWith($previousHash) !== null) {
            return $previousHash;
        }

        throw AuditPartitionNotPurgeable::chainIsBroken(
            $year,
            'su primera fila no arranca en la genesis ni en el sello de una purga anterior',
        );
    }

    /**
     * El registro de jornada, en una transaccion con su asiento dentro.
     *
     * @return list<RetentionTally>
     */
    private function purgeWorkRecords(RetentionRunCommand $command, RetentionReport $plan): array
    {
        return $this->connection->transaction(function () use ($command, $plan): array {
            $tallies = $this->workRecords->purge($plan->workRecordCutoff, $command->batchSize);

            $rows = array_sum(array_map(static fn (RetentionTally $tally): int => $tally->rows, $tallies));

            if ($rows === 0) {
                // No ha pasado nada y no hay nada que auditar. Mismo criterio que
                // una incidencia que ya estaba abierta.
                return $tallies;
            }

            $counts = [];

            foreach ($tallies as $tally) {
                $counts[$tally->dataset] = $tally->rows;
            }

            $this->audit->handle(new RecordAuditEntryCommand(
                actor: $this->actorFor($command),
                action: $this->purgeAction(),
                subject: AuditSubject::of('retention'),
                payload: AuditPayload::of([
                    'scope' => RetentionScope::WorkRecords->value,
                    'cutoff_date' => $plan->workRecordCutoff->format('Y-m-d'),
                    // El umbral con el que se decidio, porque puede cambiar
                    // (RF-PD-07) y sin el la purga no se puede releer.
                    'retention_years' => $plan->policy->legalRecordYears,
                    'site_id' => $plan->policy->siteId,
                    'rows' => $rows,
                    'tables' => $counts,
                    'confirmation' => $plan->confirmationToken(),
                ]),
            ));

            return $tallies;
        });
    }

    /**
     * Sella y suelta cada particion, y deja sus dos asientos.
     *
     * @param  list<AuditChainAnchor>  $anchors
     * @return list<RetentionTally>
     */
    private function dropPartitions(array $anchors, RetentionRunCommand $command): array
    {
        $tallies = [];

        foreach ($anchors as $anchor) {
            // La transaccion del rol de mantenimiento vive dentro del adaptador:
            // sellar y soltar son un solo acto (ADR-027).
            $this->partitions->sealAndDrop($anchor);

            $this->recordPartitionPurge($anchor, $command);

            $tallies[] = new RetentionTally(
                scope: RetentionScope::AuditLog,
                dataset: 'audit_log_'.$anchor->partitionYear,
                rows: $anchor->rowCount,
                oldest: $anchor->partitionYear.'-01-01',
                newest: $anchor->partitionYear.'-12-31',
            );
        }

        return $tallies;
    }

    private function recordPartitionPurge(AuditChainAnchor $anchor, RetentionRunCommand $command): void
    {
        $this->connection->transaction(function () use ($anchor, $command): void {
            $actor = $this->actorFor($command);

            $this->audit->handle(new RecordAuditEntryCommand(
                actor: $actor,
                action: AuditAction::RetentionPartitionSealed,
                subject: AuditSubject::of('retention', $anchor->partitionYear),
                payload: AuditPayload::of([
                    'partition_year' => $anchor->partitionYear,
                    'row_count' => $anchor->rowCount,
                    // Los dos extremos del tramo sellado: es lo que permite
                    // reconstruir despues que se solto exactamente.
                    'first_hash' => $anchor->firstHash,
                    'last_hash' => $anchor->lastHash,
                    'sealed_by' => $anchor->sealedBy,
                ]),
            ));

            $this->audit->handle(new RecordAuditEntryCommand(
                actor: $actor,
                action: AuditAction::RetentionPartitionDropped,
                subject: AuditSubject::of('retention', $anchor->partitionYear),
                payload: AuditPayload::of([
                    'partition_year' => $anchor->partitionYear,
                    'row_count' => $anchor->rowCount,
                    'last_hash' => $anchor->lastHash,
                ]),
            ));
        });
    }

    /**
     * El ciclo corto de 90 dias (RL-11), que corre aunque no haya nada legal que
     * purgar y **no deja asiento**: ni valor probatorio ni datos personales.
     *
     * @return list<RetentionTally>
     */
    private function purgeShortCycles(RetentionRunCommand $command, RetentionReport $plan): array
    {
        $tallies = [];

        foreach ([$this->technicalLog, $this->errorHistory] as $archive) {
            $cutoff = $plan->shortCycleCutoffs[$archive->scope()->value] ?? null;

            if ($cutoff === null) {
                continue;
            }

            $tallies[] = $archive->purge($cutoff, $command->batchSize);
        }

        return $tallies;
    }

    private function finish(RetentionReport $report, DateTimeImmutable $now): RetentionOutcome
    {
        $path = $this->reports->store($report);

        $this->metrics->recordRun($report, $now);

        return new RetentionOutcome($report, $path);
    }

    /**
     * Quien responde de la purga.
     *
     * `user` cuando se indica la cuenta de gestion que la autoriza; `system`
     * cuando la lanza quien tiene acceso al servidor sin sesion en el panel, que
     * es la verdad y se cruza con el registro de acceso a la maquina. Decir
     * «usuario desconocido» seria peor que decir la verdad (ADR-039).
     */
    private function actorFor(RetentionRunCommand $command): AuditActor
    {
        return $command->responsibleUserId === null
            ? AuditActor::system()
            : AuditActor::user($command->responsibleUserId);
    }

    /**
     * La accion con la que se apunta la purga del registro de jornada: filas
     * vencidas borradas de las tablas del registro, que no es soltar una
     * particion (`retention.partition_dropped`). El payload lleva el alcance,
     * la fecha de corte, el umbral aplicado y el recuento por tabla.
     */
    private function purgeAction(): AuditAction
    {
        return AuditAction::RetentionPurgeExecuted;
    }
}
