<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\AuditPartitionInventory;
use App\Modules\Compliance\Application\Port\ErrorHistoryArchive;
use App\Modules\Compliance\Application\Port\RetentionPolicyProvider;
use App\Modules\Compliance\Application\Port\TechnicalLogArchive;
use App\Modules\Compliance\Application\Port\WorkRecordArchive;
use App\Modules\Compliance\Domain\ValueObject\RetentionMode;
use App\Modules\Compliance\Domain\ValueObject\RetentionReport;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use DateTimeImmutable;

/**
 * Que se purgaria hoy, sin tocar nada (RF-PR-03, «modo simulacion»).
 *
 * **Es de solo lectura y no puede dejar de serlo.** Sus cuatro puertos de
 * lectura no exponen ningun metodo que borre —el de la aplicacion no llega
 * siquiera al rol capaz de soltar una particion, {@see AuditPartitionInventory}—,
 * asi que el `--dry-run` no depende de que nadie se acuerde de no borrar: no
 * tiene por donde.
 *
 * **Lo usan los dos modos.** La ejecucion real empieza calculando exactamente
 * este mismo plan y comparando su frase de confirmacion con la que trae quien
 * ejecuta: asi lo que se purga es lo que se aprobo y no lo que hoy toque.
 */
final readonly class PlanRetention
{
    public function __construct(
        private RetentionPolicyProvider $policies,
        private WorkRecordArchive $workRecords,
        private AuditPartitionInventory $partitions,
        private TechnicalLogArchive $technicalLog,
        private ErrorHistoryArchive $errorHistory,
    ) {}

    public function handle(DateTimeImmutable $now): RetentionReport
    {
        $policy = $this->policies->forInstallation();
        $cutoff = $policy->workRecordCutoff($now);

        $years = array_values(array_filter(
            $this->partitions->attachedYears(),
            static fn (int $year): bool => $policy->purgesAuditPartition($year, $now),
        ));

        $shortCycleCutoffs = [];
        $shortCycleTallies = [];

        foreach ([$this->technicalLog, $this->errorHistory] as $archive) {
            $scope = $archive->scope();
            $scopeCutoff = $policy->shortCycleCutoff($scope, $now);
            $shortCycleCutoffs[$scope->value] = $scopeCutoff;
            $shortCycleTallies[] = $archive->inspect($scopeCutoff);
        }

        return new RetentionReport(
            mode: RetentionMode::Simulation,
            generatedAt: $now,
            policy: $this->policies->snapshot(),
            workRecordCutoff: $cutoff,
            auditPartitionYears: $years,
            shortCycleCutoffs: $shortCycleCutoffs,
            tallies: [
                ...$this->workRecords->inspect($cutoff),
                ...$this->auditPartitionTallies($years),
                ...$shortCycleTallies,
            ],
        );
    }

    /**
     * @param  list<int>  $years
     * @return list<RetentionTally>
     */
    private function auditPartitionTallies(array $years): array
    {
        return array_map(
            fn (int $year): RetentionTally => $this->partitions->summarize($year),
            $years,
        );
    }
}
