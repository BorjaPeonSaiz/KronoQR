<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * El plan de implementacion leido como datos: que tareas hay, de que fase es
 * cada fichero, y que requisitos cita cada tarea.
 *
 * Sirve para responder a una pregunta que ningun documento contesta solo:
 * **¿quien construye este requisito?** RF-ID-09 existia en el doc 01, y ninguna
 * tarea del plan lo construia. Nadie lo noto hasta una auditoria manual, porque
 * la unica forma de verlo era leer los seis ficheros del plan a la vez.
 */
final readonly class PlanIndex
{
    /** `### Tarea 1.13 — Provision, entrega y restablecimiento del PIN` */
    private const string TASK_HEADING = '/^###\s+Tarea\s+(?<number>\d+\.\d+[a-z]?)\s*[—–-]\s*(?<title>.+)$/mu';

    /** `| **Fase** | 1 — MVP de fichaje |` en la cabecera del fichero. */
    private const string FILE_PHASE = '/^\|\s*\*\*Fase\*\*\s*\|\s*(?<phase>\d)/mu';

    /**
     * @param  list<PlanTask>  $tasks
     * @param  array<string, list<PlanTask>>  $byRequirement
     */
    private function __construct(
        public array $tasks,
        public array $byRequirement,
    ) {}

    public static function fromDirectory(string $directory): self
    {
        if (! is_dir($directory)) {
            throw new RuntimeException(
                'No se encuentra el plan de implementacion en '.$directory.'. Dentro del contenedor '
                .'llega por el montaje de la raiz del repositorio (/var/www/repo) de infra/compose.dev.yaml.'
            );
        }

        $tasks = [];
        $byRequirement = [];

        foreach (Finder::create()->files()->in($directory)->depth(0)->name('*.md')->sortByName() as $file) {
            $contents = (string) $file->getContents();
            $phase = self::phaseOf($contents);

            if ($phase === null) {
                continue;   // README, herramientas y entorno, entrega: no son de una fase
            }

            foreach (self::tasksIn($contents, $phase, $file->getFilename()) as [$task, $body]) {
                $tasks[] = $task;

                foreach (self::requirementsIn($body) as $id) {
                    $byRequirement[$id][] = $task;
                }
            }
        }

        if ($tasks === []) {
            throw new RuntimeException(
                'No se ha reconocido ninguna tarea en '.$directory.'. Las tareas se declaran como '
                .'«### Tarea N.M — Titulo» y el fichero declara su fase en la cabecera.'
            );
        }

        return new self($tasks, $byRequirement);
    }

    /**
     * Requisitos que ninguna tarea del plan construye.
     *
     * @param  list<Requirement>  $requirements
     * @return list<Requirement>
     */
    public function unbuilt(array $requirements): array
    {
        return array_values(array_filter(
            $requirements,
            fn (Requirement $requirement): bool => ! isset($this->byRequirement[$requirement->id]),
        ));
    }

    /**
     * Requisitos cuya fase en el catalogo no coincide con la del fichero de plan
     * que contiene su tarea. Es el caso de RN-11 y RN-12, que el Anexo A situaba
     * en la Fase 2 y se implementaban en la 3.
     *
     * @param  list<Requirement>  $requirements
     * @return list<array{requirement: Requirement, tasks: list<PlanTask>}>
     */
    public function misplaced(array $requirements): array
    {
        $out = [];

        foreach ($requirements as $requirement) {
            $tasks = $this->byRequirement[$requirement->id] ?? [];

            if ($tasks === []) {
                continue;   // eso lo denuncia unbuilt(), no esto
            }

            $elsewhere = array_values(array_filter(
                $tasks,
                static fn (PlanTask $task): bool => $task->phase !== $requirement->phase,
            ));

            // Solo es divergencia si NINGUNA tarea esta en la fase declarada: un
            // requisito puede tocarse en varias fases —RF-ID-01 es basico en la
            // 1 y completo en la 2— y eso no es un error.
            if (count($elsewhere) === count($tasks)) {
                $out[] = ['requirement' => $requirement, 'tasks' => $tasks];
            }
        }

        return $out;
    }

    private static function phaseOf(string $contents): ?int
    {
        return preg_match(self::FILE_PHASE, $contents, $match) === 1 ? (int) $match['phase'] : null;
    }

    /**
     * @return list<array{0: PlanTask, 1: string}>
     */
    private static function tasksIn(string $contents, int $phase, string $file): array
    {
        preg_match_all(self::TASK_HEADING, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $out = [];
        $total = count($matches);

        foreach ($matches as $index => $match) {
            $start = (int) $match[0][1];
            $end = $index + 1 < $total ? (int) $matches[$index + 1][0][1] : strlen($contents);

            $out[] = [
                new PlanTask((string) $match['number'][0], $phase, trim((string) $match['title'][0]), $file),
                substr($contents, $start, $end - $start),
            ];
        }

        return $out;
    }

    /**
     * Los identificadores que cita el cuerpo de una tarea, con los rangos ya
     * expandidos: `RF-ID-04..09` cuenta como las seis tareas que cubre, no como
     * una cadena literal que no coincide con nada.
     *
     * @return list<string>
     */
    private static function requirementsIn(string $body): array
    {
        return RequirementRange::findAll($body);
    }
}
