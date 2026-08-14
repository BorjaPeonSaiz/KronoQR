<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality;

use App\Console\Commands\Quality\Support\AdrRegistry;
use App\Console\Commands\Quality\Support\PlanIndex;
use App\Console\Commands\Quality\Support\PlanTask;
use App\Console\Commands\Quality\Support\Requirement;
use App\Console\Commands\Quality\Support\RequirementCatalog;
use Illuminate\Console\Command;

/**
 * `php artisan docs:consistency` — coherencia entre los documentos que mandan y
 * los ficheros que los ejecutan.
 *
 * Por que existe. Los seis hallazgos bloqueantes de la auditoria previa al
 * arranque fueron **el mismo fallo repetido**: una decision se registra en un
 * documento y no se propaga a los otros. ADR-026, 027 y 028 vivian solo como
 * fila de tabla —autoridad #4— siendo autoridad #1. RF-ID-09 existia como
 * requisito y ninguna tarea lo construia. RN-11 y RN-12 estaban en la Fase 2 del
 * Anexo A y se implementaban en la 3.
 *
 * Los tres son mecanicamente detectables, y ninguno lo detecto una maquina: los
 * encontro una persona leyendo seis ficheros a la vez. El plan ya aplica al
 * codigo el principio de que «una convencion que no verifica una herramienta es
 * una sugerencia» (doc 02 §3.5). Este comando se lo aplica a si mismo.
 *
 * Vive en app/Console/Commands/Quality/ y no en un modulo: es herramienta del
 * repositorio y no se ejecuta jamas en la instalacion de un cliente.
 */
final class DocsConsistencyCommand extends Command
{
    protected $signature = 'docs:consistency
        {--check : Codigo de salida distinto de cero si hay alguna incoherencia}';

    protected $description = 'Comprueba que los documentos que mandan y los ficheros que los ejecutan no divergen (RQ-12, RNF-M-04)';

    /** @var list<string> */
    private array $problems = [];

    public function handle(): int
    {
        $docs = rtrim(config()->string('quality.docs_path'), '/');
        $repo = rtrim(config()->string('quality.repo_path'), '/');

        $catalog = RequirementCatalog::fromFile($docs.'/'.config()->string('quality.requirements_file'));
        $plan = PlanIndex::fromDirectory($repo.'/'.config()->string('quality.plan_path'));

        $this->checkAdrRegistry(
            AdrRegistry::fromPaths(
                $docs.'/'.config()->string('quality.stack_file'),
                $docs.'/'.config()->string('quality.adr_path'),
            )
        );

        $this->checkEveryRequirementIsBuilt($plan, $catalog->requirements);
        $this->checkPhasesAgree($plan, $catalog->requirements);

        $this->line('ADR: '.count($catalog->requirements).' requisitos y '
            .count($plan->tasks).' tareas del plan comprobados.');

        return $this->report();
    }

    /**
     * Cada fila del §4 del doc 02 tiene su ADR, y cada ADR su fila. Las dos
     * direcciones: un fichero sin fila tampoco lo encuentra quien no sabe que
     * existe, porque el §4 es el indice por el que se entra.
     */
    private function checkAdrRegistry(AdrRegistry $registry): void
    {
        foreach ($registry->citedWithoutFile() as $number => $title) {
            $this->problems[] = 'ADR-'.$number.' («'.$title.'») lo cita el §4 del doc 02 y no existe en '
                .'docs/adr/. Una decision que solo vive como fila de tabla esta registrada en el escalon '
                .'de autoridad equivocado: se lee el resumen, pero no el contexto ni las alternativas.';
        }

        foreach ($registry->fileWithoutRow() as $number => $file) {
            $this->problems[] = 'docs/adr/'.$file.' existe y ninguna fila del §4 del doc 02 lo anuncia. '
                .'Añade su fila: el §4 es el indice por el que se entra a las decisiones.';
        }
    }

    /**
     * @param  list<Requirement>  $requirements
     */
    private function checkEveryRequirementIsBuilt(PlanIndex $plan, array $requirements): void
    {
        foreach ($plan->unbuilt($requirements) as $requirement) {
            $this->problems[] = $requirement->id.' (fase '.$requirement->phase.') no lo construye ninguna '
                .'tarea del plan. O le falta tarea, o la tarea que lo cubre no lo cita: en los dos casos, '
                .'nadie lo va a echar de menos hasta que un cliente lo pida.';
        }
    }

    /**
     * @param  list<Requirement>  $requirements
     */
    private function checkPhasesAgree(PlanIndex $plan, array $requirements): void
    {
        foreach ($plan->misplaced($requirements) as $divergence) {
            $requirement = $divergence['requirement'];
            // Sin repetir: una tarea que cita el requisito ocho veces en su
            // cuerpo aparece una. El mensaje tiene que caber en una linea de
            // terminal para que alguien lo lea.
            $where = implode(', ', array_unique(array_map(
                static fn (PlanTask $task): string => $task->describe(),
                $divergence['tasks'],
            )));

            $this->problems[] = $requirement->id.' esta en la fase '.$requirement->phase.' del Anexo A '
                .'y lo construyen tareas de otra fase: '.$where.'. Una de las dos esta mal, y la que '
                .'manda sobre QUE hace el producto es el doc 01.';
        }
    }

    private function report(): int
    {
        if ($this->problems === []) {
            $this->info('Coherencia documental: sin divergencias.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('Coherencia documental: '.count($this->problems).' divergencia(s).');

        foreach ($this->problems as $problem) {
            $this->line('  · '.$problem);
        }

        $this->newLine();
        $this->line('Cada una es una decision registrada en un documento y no propagada a los demas.');

        return $this->option('check') === true ? self::FAILURE : self::SUCCESS;
    }
}
