<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

/**
 * La matriz requisito -> pruebas del §9.6, y las tres cosas que de ella se
 * deducen: que esta cubierto, que falta dentro del alcance y que etiquetas
 * apuntan a un requisito que no existe.
 *
 * Todo el calculo vive aqui y no en el comando para que se pueda probar sin
 * ejecutar artisan, que es la misma razon por la que el dominio del producto no
 * tiene facades dentro.
 */
final readonly class TraceabilityReport
{
    /** @var array<string, list<TaggedTest>> */
    private array $tests;

    public function __construct(
        private RequirementCatalog $catalog,
        private TagScan $scan,
        private PhaseOrder $order,
        private int $currentPhase,
    ) {
        $this->tests = $scan->byRequirement();
    }

    /*
     * Accesores de lectura. El comando los usa para contar lo explorado y decir
     * lo que NO ha podido mirar: un extractor que anuncia «0 pruebas» sin
     * distinguir «no hay» de «no he podido verlas» da una garantia que no
     * presta.
     */

    public function catalog(): RequirementCatalog
    {
        return $this->catalog;
    }

    public function scan(): TagScan
    {
        return $this->scan;
    }

    public function order(): PhaseOrder
    {
        return $this->order;
    }

    public function currentPhase(): int
    {
        return $this->currentPhase;
    }

    /**
     * Requisitos cuya fase ya se ha ejecutado y que ninguna prueba referencia.
     * Es exactamente lo que bloquea `--check` (RQ-13).
     *
     * @return list<Requirement>
     */
    public function missingInScope(): array
    {
        return array_values(array_filter(
            $this->catalog->inScope($this->order, $this->currentPhase),
            fn (Requirement $requirement): bool => $requirement->requiresTest()
                && ! isset($this->tests[$requirement->id]),
        ));
    }

    /**
     * Los que verifica una persona, no una herramienta. No bloquean, pero se
     * enseñan con nombre y apellidos: un requisito que nadie comprueba y del
     * que nadie habla es indistinguible de uno olvidado.
     *
     * @return list<Requirement>
     */
    public function reviewedByHand(): array
    {
        return array_values(array_filter(
            $this->catalog->requirements,
            static fn (Requirement $requirement): bool => ! $requirement->requiresTest(),
        ));
    }

    /**
     * Etiquetas con forma de requisito que no figuran en el catalogo. No
     * bloquean —el requisito que se hayan querido nombrar aparecera sin prueba
     * por su cuenta— pero se enseñan: casi siempre son una errata o un requisito
     * que el Anexo A no reparte a ninguna fase.
     *
     * @return array<string, list<TaggedTest>>
     */
    public function unknownTags(): array
    {
        $known = $this->catalog->phases();

        return array_filter(
            $this->tests,
            static fn (array $tests, int|string $id): bool => ! isset($known[(string) $id]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return list<array{phase: int, executed: bool, total: int, covered: int, missing: int}>
     */
    public function phaseSummary(): array
    {
        $summary = [];

        foreach ($this->order->all() as $phase) {
            $requirements = array_filter(
                $this->catalog->requirements,
                static fn (Requirement $requirement): bool => $requirement->phase === $phase,
            );

            $covered = array_filter(
                $requirements,
                fn (Requirement $requirement): bool => isset($this->tests[$requirement->id]),
            );

            $summary[] = [
                'phase' => $phase,
                'executed' => $this->order->isExecutedBy($phase, $this->currentPhase),
                'total' => count($requirements),
                'covered' => count($covered),
                'missing' => count($requirements) - count($covered),
            ];
        }

        return $summary;
    }

    public function toMarkdown(): string
    {
        return implode("\n", [
            ...$this->heading(),
            ...$this->summarySection(),
            ...$this->missingSection(),
            ...$this->matrixSection(),
            ...$this->warningSection(),
            '',
        ]);
    }

    /**
     * @return list<string>
     */
    private function heading(): array
    {
        return [
            '# Trazabilidad requisito → prueba',
            '',
            '<!-- Generado por `php artisan qa:traceability` (doc 02 §9.6, RQ-13). No se edita a mano. -->',
            '',
            'Cada prueba declara qué requisitos cubre con `->group(...)` en Pest o `{ tag: [...] }` en',
            'Playwright. Esta matriz es la evidencia documental de que cada obligación —y en particular',
            'cada `RL-*`— tiene una prueba automática que la verifica en cada cambio.',
            '',
            'No lleva fecha a propósito: dos ejecuciones sobre el mismo árbol producen el mismo fichero,',
            'así que un `git diff` sobre esta matriz solo enseña cambios reales de cobertura.',
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function summarySection(): array
    {
        $pest = count($this->scan->by('pest'));
        $playwright = count($this->scan->by('playwright'));

        $rows = ['| Fase | ¿Ejecutada? | Requisitos | Con prueba | Sin prueba |', '|---|---|---|---|---|'];

        foreach ($this->phaseSummary() as $phase) {
            $rows[] = '| '.$phase['phase'].' | '.($phase['executed'] ? 'sí' : 'no')
                .' | '.$phase['total'].' | '.$phase['covered'].' | '.$phase['missing'].' |';
        }

        return [
            '## Alcance',
            '',
            '- Catálogo: `docs/requisitos.yaml`, **'.count($this->catalog->requirements).' requisitos**.',
            '- Fase en curso (`quality.current_phase`): **'.$this->currentPhase.'**. Orden real de ejecución: '.$this->order->describe().'.',
            '- Pruebas etiquetadas: **'.count($this->scan->tests).'** (Pest '.$pest.', Playwright '.$playwright.').',
            '- Bloquean solo las fases ya ejecutadas: un requisito de la Fase 3 no bloquea mientras se trabaja en la Fase 1.',
            '',
            ...$rows,
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function missingSection(): array
    {
        $missing = $this->missingInScope();

        if ($missing === []) {
            return ['## Requisitos en alcance sin prueba', '', 'Ninguno.', ''];
        }

        $rows = ['| Requisito | Fase | Enunciado |', '|---|---|---|'];

        foreach ($missing as $requirement) {
            $rows[] = '| `'.$requirement->id.'` | '.$requirement->phase.' | '.self::cell($requirement->title).' |';
        }

        return [
            '## Requisitos en alcance sin prueba',
            '',
            'Estos **bloquean** `qa:traceability --check` y, con él, la etapa ③b de la CI.',
            '',
            ...$rows,
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function matrixSection(): array
    {
        $rows = ['| Requisito | Fase | Pruebas | Enunciado |', '|---|---|---|---|'];

        foreach ($this->catalog->requirements as $requirement) {
            $tests = $this->tests[$requirement->id] ?? [];

            $rows[] = '| `'.$requirement->id.'` | '.$requirement->phase.' | '
                .($tests === [] ? '—' : implode('<br>', array_map(self::describe(...), $tests)))
                .' | '.self::cell($requirement->title).' |';
        }

        return ['## Matriz', '', ...$rows, ''];
    }

    /**
     * @return list<string>
     */
    private function warningSection(): array
    {
        $lines = ['## Avisos', ''];
        $warnings = [];

        foreach ($this->unknownTags() as $id => $tests) {
            $warnings[] = '- `'.$id.'`: '.count($tests).' prueba(s) la citan y no figura en `docs/requisitos.yaml`.';
        }

        foreach ($this->scan->malformed as $tag) {
            $warnings[] = '- Etiqueta con forma de requisito que no lo es: '.$tag;
        }

        foreach ($this->scan->missingRoots as $tool => $roots) {
            foreach ($roots as $root) {
                $warnings[] = '- No existe el directorio de pruebas de '.$tool.': `'.$root.'`.';
            }
        }

        return [...$lines, ...($warnings === [] ? ['Ninguno.'] : $warnings), ''];
    }

    private static function describe(TaggedTest $test): string
    {
        return '`'.$test->reference().'` — '.self::cell($test->name);
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }
}
