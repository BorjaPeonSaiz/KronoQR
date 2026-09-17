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
    /**
     * Cada herramienta y CADA CUANTO se ejecuta de verdad (doc 02 §10.1).
     *
     * Va escrito en cada entrada de la matriz porque la matriz se entrega como
     * evidencia de que «cada obligacion tiene una prueba automatica que la
     * verifica EN CADA CAMBIO», y de k6 eso es falso: la prueba de carga dura
     * minutos, necesita la pila levantada y corre a mano y en la etiqueta de
     * cada version mayor (RQ-08, decision 12 de la tarea 3.6). Una entrada de
     * k6 acredita que el umbral se midio en la ultima version mayor, no que se
     * este verificando hoy, y quien lee la matriz tiene que poder distinguirlo
     * sin salir de la fila.
     *
     * Es texto fijo por herramienta, no una fecha: dos ejecuciones sobre el
     * mismo arbol tienen que producir el mismo fichero.
     *
     * @var array<string, string>
     */
    private const array CADENCE = [
        'pest' => 'Pest, en cada push',
        'playwright' => 'Playwright, en cada push',
        'k6' => 'k6, a mano y en cada etiqueta vX.0.0',
    ];

    /**
     * Las herramientas que corren EN CADA PUSH, que es el caso por defecto y
     * por eso no se anota en la matriz.
     *
     * Anotar las 3.300 entradas de Pest para decir lo que ya dice el preambulo
     * engordaba el fichero un 18 % sin informar de nada: lo que hay que ver de
     * un vistazo es lo que se APARTA del caso normal.
     *
     * @var list<string>
     */
    private const array EVERY_PUSH = ['pest', 'playwright'];

    /** El marcador corto de lo que no corre en cada push. */
    private const array OFF_CADENCE = ['k6' => 'k6: a mano y en cada etiqueta vX.0.0'];

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
            ...$this->conditionalSection(),
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
            'Cada prueba declara qué requisitos cubre con `->group(...)` en Pest, `{ tag: [...] }` en',
            'Playwright o `tags: { requirements: \'...\' }` en el escenario de k6. Esta matriz es la',
            'evidencia documental de que cada obligación —y en particular cada `RL-*`— tiene una prueba',
            'automática que la verifica.',
            '',
            '**Las tres herramientas no corren con la misma frecuencia**, y las entradas que se apartan del',
            'caso normal lo llevan escrito al lado:',
            '',
            '- **Pest, en cada push** — etapas ①–④ del pipeline (doc 02 §10.1).',
            '- **Playwright, en cada push** — etapa ⑦, E2E con cámara simulada.',
            '- **k6, a mano y en cada etiqueta `vX.0.0`** — la prueba de carga dura minutos y necesita la',
            '  pila levantada, así que está fuera del pipeline de cada cambio (RQ-08, doc 02 §10.1). Una',
            '  entrada de k6 acredita que el umbral **se midió en la última versión mayor**, no que se',
            '  esté verificando hoy. Se marca `(k6: a mano y en cada etiqueta vX.0.0)`.',
            '',
            '**Una entrada sin marcador se verifica en cada push.** Solo se anota lo que se aparta de ahí,',
            'que es lo único que hay que ver de un vistazo.',
            '',
            'Las pruebas que se saltan solas cuando su entorno no tiene la herramienta que necesitan',
            '—`->skip(! hayChromium(), ...)`— cuentan como cobertura, se marcan `(si el entorno la deja',
            'correr)` y se enumeran además en su propio apartado: verifican lo que dicen, pero no en todas',
            'las máquinas.',
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
        $k6 = count($this->scan->by('k6'));

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
            '- Pruebas etiquetadas: **'.count($this->scan->tests).'** (Pest '.$pest.', Playwright '.$playwright.', k6 '.$k6.').',
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
     * Las pruebas que se saltan solas cuando el entorno no trae lo que
     * necesitan: Chromium para sellar un PDF, `php-fpm` para comprobar el pool
     * rendido. NO son pruebas saltadas —se ejecutan y verifican en cuanto la
     * herramienta esta— y por eso cuentan como cobertura.
     *
     * Se enumeran igualmente, y con su nombre: un verde que depende de que
     * maquina lo ejecute es una reserva sobre la evidencia, y la reserva se
     * escribe donde se lee la evidencia. El §9.6 no las tenia contempladas y
     * salian entre los avisos con el rotulo de «etiqueta con forma de requisito
     * que no lo es», que era sencillamente falso.
     *
     * @return list<string>
     */
    private function conditionalSection(): array
    {
        $conditional = array_values(array_filter(
            $this->scan->tests,
            static fn (TaggedTest $test): bool => $test->conditional,
        ));

        $heading = ['## Pruebas condicionadas al entorno', ''];

        if ($conditional === []) {
            return [...$heading, 'Ninguna.', ''];
        }

        $rows = ['| Prueba | Requisitos | Se ejecuta |', '|---|---|---|'];

        foreach ($conditional as $test) {
            $rows[] = '| `'.$test->reference().'` — '.self::cell($test->name)
                .' | '.implode(', ', array_map(static fn (string $id): string => '`'.$id.'`', $test->requirements))
                .' | '.self::cadence($test).' |';
        }

        return [
            ...$heading,
            'Se saltan solas donde su herramienta no está instalada y se ejecutan donde sí. Cuentan como',
            'cobertura, con esa reserva.',
            '',
            ...$rows,
            '',
        ];
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

        // El aviso llega ya redactado desde el escaner: son dos cosas distintas
        // —una etiqueta mal escrita y una prueba saltada que no cubre lo que
        // dice— y rotularlas a las dos igual, como se hacia, describia mal la
        // mitad de ellas.
        foreach ($this->scan->malformed as $warning) {
            $warnings[] = '- '.$warning;
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
        return '`'.$test->reference().'` — '.self::cell($test->name).self::mark($test);
    }

    /**
     * Lo que hay que saber de esta entrada que NO vale para la de al lado: que
     * no corre en cada push, o que su verde depende del entorno. Las demas no
     * llevan nada, y el preambulo dice lo que significa no llevarlo.
     *
     * Una herramienta nueva sin cadencia declarada lo DICE en vez de callarse:
     * dar por «en cada push» algo que no lo es es justo el fallo que este
     * marcador existe para evitar.
     */
    private static function mark(TaggedTest $test): string
    {
        $notes = [];

        if (! in_array($test->tool, self::EVERY_PUSH, true)) {
            $notes[] = self::OFF_CADENCE[$test->tool] ?? $test->tool.': frecuencia sin declarar';
        }

        if ($test->conditional) {
            $notes[] = 'si el entorno la deja correr';
        }

        return $notes === [] ? '' : ' ('.implode(', ', $notes).')';
    }

    /** Con que frecuencia se ejecuta esta prueba, en largo. */
    private static function cadence(TaggedTest $test): string
    {
        return self::CADENCE[$test->tool] ?? $test->tool.', frecuencia sin declarar';
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }
}
