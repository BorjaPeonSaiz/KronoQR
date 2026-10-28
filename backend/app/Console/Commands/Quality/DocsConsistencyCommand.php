<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality;

use App\Console\Commands\Quality\Support\AdrRegistry;
use App\Console\Commands\Quality\Support\ContractOperations;
use App\Console\Commands\Quality\Support\PlanIndex;
use App\Console\Commands\Quality\Support\PlanTask;
use App\Console\Commands\Quality\Support\Requirement;
use App\Console\Commands\Quality\Support\RequirementCatalog;
use App\Console\Commands\Quality\Support\RequirementCitations;
use App\Console\Commands\Quality\Support\SourceTree;
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
 *
 * ## El orden «doc 01 -> contrato -> codigo»
 *
 * Una decision que llega al codigo o al contrato sin pasar antes por el doc 01
 * es comportamiento que nadie ha decidido como requisito: ni se prueba contra
 * el, ni se le ha contado al cliente. Ya ocurrio con la actualizacion urgente
 * de la tablet y con el rol de quien firma por consola. Lo que se puede
 * comprobar de forma mecanica, y se comprueba:
 *
 * 1. **Cada operacion del contrato cita al menos un requisito** en su resumen o
 *    su descripcion. Un endpoint que no responde a ningun requisito es
 *    comportamiento que el doc 01 no ha decidido.
 * 2. **Todo requisito que cita el contrato existe en el catalogo**
 *    (`requisitos.yaml`, generado del Anexo A). Un identificador en el contrato
 *    que el doc 01 no define es un requisito escrito en el escalon equivocado.
 * 3. **Cada controlador y cada politica del dominio cita al menos un
 *    requisito**: son las puertas por las que entra una ruta y una regla.
 * 4. **Todo requisito que cita el codigo de produccion existe en el catalogo**.
 *
 * Lo que **no** detecta, y por eso no sustituye a la revision:
 *
 * - **Que el requisito citado diga lo que hace el codigo.** Los dos casos de
 *   arriba citaban requisitos que existian (RF-KI-07, RN-23): faltaba la
 *   excepcion o el rol en su texto. Comparar prosa con comportamiento no es
 *   mecanico.
 * - Comportamiento nuevo dentro de un fichero que ya cita algun requisito: una
 *   rama mas en un controlador citado pasa sin ruido.
 * - Casos de uso, ordenes de consola y vistas de los frontends sin cita (ver
 *   `requirement_citations` en config/quality.php para el porque).
 * - Rutas del router sin contrato y operaciones sin ruta: eso ya lo exige
 *   `tests/Contract/OpenApiContractTest.php`, en los dos sentidos.
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

        $contract = ContractOperations::fromFile($docs.'/'.config()->string('quality.contract_file'));
        $this->checkContractCitesRequirements($contract, $catalog->phases());

        /** @var list<string> $mustCite */
        $mustCite = config()->array('quality.requirement_citations.must_cite');
        /** @var list<string> $mustExist */
        $mustExist = config()->array('quality.requirement_citations.must_exist');

        $this->checkCodeCitesRequirements(SourceTree::of($mustCite), $catalog->phases());
        $this->checkCodeCitationsExist(SourceTree::of($mustExist), $catalog->phases());

        $this->line('ADR: '.count($catalog->requirements).' requisitos, '
            .count($plan->tasks).' tareas del plan y '
            .count($contract->operations).' operaciones del contrato comprobados.');

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

        foreach ($registry->statusDivergences() as $number => $divergence) {
            $this->problems[] = 'ADR-'.$number.': '.$divergence.'. Pon al dia el estado en cursiva de su '
                .'fila del §4 del doc 02: quien entra por el indice lee un estado que la decision ya no tiene.';
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

    /**
     * Comprobaciones 1 y 2 del docblock de la clase.
     *
     * @param  array<string, int>  $catalog
     */
    private function checkContractCitesRequirements(ContractOperations $contract, array $catalog): void
    {
        foreach ($contract->operations as $operation) {
            if (RequirementCitations::unknown($operation->citations, $catalog) === $operation->citations) {
                $this->problems[] = 'El contrato describe '.$operation->describe().' sin citar ningun requisito '
                    .'del doc 01. Cita en su descripcion el RF-/RN-/RS-/RL- que lo decide; si no hay ninguno, '
                    .'el requisito se escribe antes en el doc 01 (orden «doc 01 -> contrato -> codigo»).';
            }
        }

        foreach (RequirementCitations::unknown($contract->citations, $catalog) as $id) {
            $this->problems[] = 'El contrato cita '.$id.', que el doc 01 no define (requisitos.yaml). '
                .'O el requisito se escribio en el contrato antes que en el doc 01, o el identificador esta mal.';
        }
    }

    /**
     * Comprobacion 3 del docblock de la clase.
     *
     * @param  array<string, int>  $catalog
     */
    private function checkCodeCitesRequirements(SourceTree $tree, array $catalog): void
    {
        $this->reportMissingDirectories($tree);

        foreach ($tree->files as $file => $contents) {
            $cited = RequirementCitations::in($contents);

            if (RequirementCitations::unknown($cited, $catalog) === $cited) {
                $this->problems[] = $this->relative($file).' no cita ningun requisito del doc 01. Es una puerta '
                    .'de comportamiento (una ruta o una regla): cita el requisito que la decide, o escribelo '
                    .'antes en el doc 01.';
            }
        }
    }

    /**
     * Comprobacion 4 del docblock de la clase.
     *
     * @param  array<string, int>  $catalog
     */
    private function checkCodeCitationsExist(SourceTree $tree, array $catalog): void
    {
        $this->reportMissingDirectories($tree);

        foreach ($tree->files as $file => $contents) {
            foreach (RequirementCitations::unknown(RequirementCitations::in($contents), $catalog) as $id) {
                $this->problems[] = $this->relative($file).' cita '.$id.', que el doc 01 no define '
                    .'(requisitos.yaml). El codigo no puede ir por delante del requisito.';
            }
        }
    }

    private function reportMissingDirectories(SourceTree $tree): void
    {
        foreach ($tree->missing as $pattern) {
            $this->problems[] = 'No existe ningun directorio para '.$pattern.' (config/quality.php, '
                .'requirement_citations). Una exploracion parcial no comprueba nada: corrige la ruta.';
        }
    }

    private function relative(string $file): string
    {
        foreach ([base_path(), rtrim(config()->string('quality.repo_path'), '/')] as $root) {
            if (str_starts_with($file, $root.'/')) {
                return substr($file, \strlen($root) + 1);
            }
        }

        return $file;
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
