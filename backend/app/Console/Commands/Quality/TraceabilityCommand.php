<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality;

use App\Console\Commands\Quality\Support\PhaseOrder;
use App\Console\Commands\Quality\Support\Requirement;
use App\Console\Commands\Quality\Support\RequirementCatalog;
use App\Console\Commands\Quality\Support\TagScan;
use App\Console\Commands\Quality\Support\TagScanner;
use App\Console\Commands\Quality\Support\TraceabilityReport;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php artisan qa:traceability` — matriz requisito -> pruebas (RQ-13, §9.6).
 *
 * Vive en app/Console/Commands/ y NO en un modulo de negocio: es una
 * herramienta del repositorio, no funcionalidad del producto. No se ejecuta
 * jamas en la instalacion de un cliente, no toca la base de datos y necesita el
 * arbol de fuentes delante. Meterla en `Product` le daria una frontera de
 * Deptrac que no le corresponde y la enviaria al paquete que se entrega.
 *
 * Con `--check` no escribe nada y falla si un requisito **cuya fase ya se ha
 * ejecutado** no tiene ninguna prueba que lo referencie. Es lo que ejecuta la
 * etapa ③b de la CI.
 */
final class TraceabilityCommand extends Command
{
    protected $signature = 'qa:traceability
        {--check : No escribe nada. Falla si un requisito ya implementado no tiene prueba}
        {--output= : Fichero a generar. «-» escribe la matriz en la salida estandar}';

    protected $description = 'Genera la matriz requisito → pruebas y comprueba que no falta ninguna (RQ-13)';

    public function handle(): int
    {
        $report = $this->report();

        $this->summarize($report);

        if ($this->option('check') === true) {
            return $this->check($report);
        }

        return $this->write($report);
    }

    private function report(): TraceabilityReport
    {
        $docs = $this->docsPath();

        /** @var list<int> $order */
        $order = config()->array('quality.phase_execution_order');

        /** @var array<string, list<string>> $paths */
        $paths = config()->array('quality.test_paths');

        return new TraceabilityReport(
            RequirementCatalog::fromFile($docs.'/'.config()->string('quality.requirements_file')),
            (new TagScanner($paths, dirname(base_path())))->scan(),
            new PhaseOrder($order),
            config()->integer('quality.current_phase'),
        );
    }

    /**
     * Lo que se ha mirado y lo que no. Un extractor que anuncia «0 pruebas» sin
     * decir si es que no hay ninguna o que no ha podido verlas da una garantia
     * que no presta: los frontends no estan montados en el contenedor `app`, y
     * ahi la exploracion de Playwright es necesariamente parcial.
     */
    private function summarize(TraceabilityReport $report): void
    {
        $scan = $report->scan();

        $this->log()->writeln('Catálogo: '.count($report->catalog()->requirements).' requisitos'
            .' · fase en curso '.$report->currentPhase()
            .' · orden de ejecución '.$report->order()->describe());

        foreach ($scan->scannedFiles as $tool => $files) {
            $this->log()->writeln(ucfirst($tool).': '.count($scan->by($tool)).' pruebas etiquetadas en '.$files.' ficheros explorados.');
        }

        $this->warnAbout($scan);
    }

    private function warnAbout(TagScan $scan): void
    {
        foreach ($scan->missingRoots as $tool => $roots) {
            foreach ($roots as $root) {
                $this->log()->writeln('<comment>Aviso: no existe '.$root.', así que no se han buscado etiquetas de '.$tool.' ahí.</comment>');
            }
        }

        foreach ($scan->malformed as $tag) {
            $this->log()->writeln('<comment>Aviso: etiqueta con forma de requisito que no lo es → '.$tag.'</comment>');
        }
    }

    private function check(TraceabilityReport $report): int
    {
        foreach ($report->unknownTags() as $id => $tests) {
            $this->log()->writeln('<comment>Aviso: '.count($tests).' prueba(s) citan '.$id.', que no figura en docs/requisitos.yaml.</comment>');
        }

        $missing = $report->missingInScope();

        if ($missing === []) {
            $this->log()->writeln('<info>Trazabilidad: todos los requisitos de las fases ejecutadas tienen prueba.</info>');

            return self::SUCCESS;
        }

        $this->log()->writeln('<error>Trazabilidad: '.count($missing).' requisito(s) de una fase ya ejecutada sin ninguna prueba que los referencie.</error>');

        foreach ($missing as $requirement) {
            $this->log()->writeln('  '.$requirement->id.' (fase '.$requirement->phase.') — '.self::shorten($requirement));
        }

        $this->log()->writeln('Etiqueta la prueba que lo cubre con ->group(\''.$missing[0]->id.'\') o, si no existe, escríbela.');

        return self::FAILURE;
    }

    private function write(TraceabilityReport $report): int
    {
        $markdown = $report->toMarkdown();
        $destination = $this->option('output');
        $destination = is_string($destination) && $destination !== '' ? $destination : $this->defaultOutput();

        if ($destination === '-') {
            $this->output->write($markdown);

            return self::SUCCESS;
        }

        $this->store($destination, $markdown);
        $this->log()->writeln('<info>Matriz escrita en '.$destination.'.</info>');

        return self::SUCCESS;
    }

    /**
     * docs/ se monta de SOLO LECTURA en el contenedor (infra/compose.dev.yaml),
     * asi que ahi no se puede escribir la matriz. No se disimula: se dice cual
     * es la via, que es generarla desde el host con `make traceability`.
     */
    private function store(string $destination, string $markdown): void
    {
        $directory = dirname($destination);

        if (! is_dir($directory) || @file_put_contents($destination, $markdown) === false) {
            throw new RuntimeException(
                'No se ha podido escribir '.$destination.'. Si estás dentro del contenedor, docs/ está '
                .'montado de solo lectura: genera la matriz desde el host con «make traceability», que '
                .'ejecuta este mismo comando con --output=- y escribe el fichero fuera del contenedor.'
            );
        }
    }

    private function defaultOutput(): string
    {
        return $this->docsPath().'/'.config()->string('quality.traceability_file');
    }

    private function docsPath(): string
    {
        return rtrim(config()->string('quality.docs_path'), '/');
    }

    /**
     * Los mensajes van a la salida de error cuando la matriz viaja por la
     * estandar: `make traceability` redirige esta ultima a un fichero.
     */
    private function log(): OutputInterface
    {
        return $this->option('output') === '-'
            ? $this->output->getErrorStyle()
            : $this->output;
    }

    private static function shorten(Requirement $requirement): string
    {
        return mb_strimwidth($requirement->title, 0, 90, '…');
    }
}
