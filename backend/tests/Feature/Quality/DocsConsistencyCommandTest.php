<?php

declare(strict_types=1);

use Tests\Feature\Quality\Support\Commands;

/*
 * `docs:consistency --check` comprueba que los documentos que mandan y los
 * ficheros que los ejecutan no divergen (RQ-12, RNF-M-04).
 *
 * Existe porque los seis hallazgos bloqueantes de la auditoria previa al
 * arranque fueron el mismo fallo repetido: una decision registrada en un
 * documento y no propagada a los demas. Estas pruebas reproducen tres de
 * aquellos hallazgos —ADR sin fichero, requisito sin tarea, requisito en la fase
 * equivocada— y exigen que el comando los detecte. Sin ellas, lo unico que
 * sabiamos del comando es que hoy dice que no hay divergencias, que es
 * exactamente lo que diria si no supiera buscarlas.
 */

/**
 * Monta un repositorio de mentira coherente y apunta la configuracion a el.
 *
 * @param  array{requisitos?: string, adrRows?: string, adrFiles?: list<string>, plan?: string}  $overrides
 */
function useDocsTree(array $overrides = []): void
{
    $root = sys_get_temp_dir().'/kronoqr-tree-'.bin2hex(random_bytes(6));
    $docs = $root.'/docs';
    $plan = $root.'/plan implementacion';

    mkdir($docs.'/adr', 0o777, true);
    mkdir($plan, 0o777, true);

    file_put_contents(
        $docs.'/requisitos.yaml',
        $overrides['requisitos'] ?? "- { id: RN-05, fase: 0, titulo: El turno no se parte a medianoche }\n"
    );

    $rows = $overrides['adrRows'] ?? '| **001** | Monolito modular |';

    file_put_contents($docs.'/02-stack.md', <<<MD
        ## 4. Decisiones de arquitectura

        | ADR | Decision |
        |---|---|
        {$rows}

        ## 5. Otra cosa
        MD);

    foreach ($overrides['adrFiles'] ?? ['ADR-001-monolito-modular.md'] as $file) {
        file_put_contents($docs.'/adr/'.$file, "# Decision\n");
    }

    file_put_contents($plan.'/00-fase-0.md', $overrides['plan'] ?? <<<'MD'
        | **Fase** | 0 — Cimientos |

        ### Tarea 0.1 — Levantar el entorno

        Cubre RN-05.
        MD);

    config([
        'quality.docs_path' => $docs,
        'quality.repo_path' => $root,
        'quality.plan_path' => 'plan implementacion',
        'quality.stack_file' => '02-stack.md',
        'quality.adr_path' => 'adr',
        'quality.requirements_file' => 'requisitos.yaml',
    ]);
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/kronoqr-tree-*') ?: [] as $root) {
        foreach (['/docs/adr', '/docs', '/plan implementacion'] as $sub) {
            foreach (glob($root.$sub.'/*') ?: [] as $file) {
                @unlink($file);
            }
        }

        foreach (['/docs/adr', '/docs', '/plan implementacion'] as $sub) {
            @rmdir($root.$sub);
        }

        @rmdir($root);
    }
});

it('pasa cuando los documentos y los ficheros dicen lo mismo', function (): void {
    useDocsTree();

    expect(Commands::run('docs:consistency --check')[0])->toBe(0);
})->group('RQ-12', 'RNF-M-04');

it('detecta una decision que solo vive como fila de tabla', function (): void {
    // El caso de ADR-026, 027 y 028: registradas en el escalon de autoridad #4
    // siendo autoridad #1. Se lee el resumen, pero no el contexto ni las
    // alternativas, que es lo que hace falta el dia que alguien quiere revertir.
    useDocsTree([
        'adrRows' => "| **001** | Monolito modular |\n| **026** | La correccion supersede |",
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('ADR-026');
})->group('RQ-12', 'RNF-M-04');

it('detecta un ADR escrito que ninguna fila del indice anuncia', function (): void {
    // La otra direccion: una decision que no figura en el §4 no la encuentra
    // quien no sabe que existe.
    useDocsTree([
        'adrFiles' => ['ADR-001-monolito-modular.md', 'ADR-031-antirrebote.md'],
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('ADR-031');
})->group('RQ-12', 'RNF-M-04');

it('detecta un requisito que ninguna tarea del plan construye', function (): void {
    // EL caso RF-ID-09: existia en el doc 01 y ninguna tarea lo construia.
    // Nadie lo noto hasta una auditoria manual, porque la unica forma de verlo
    // era leer los seis ficheros del plan a la vez.
    useDocsTree([
        'requisitos' => "- { id: RN-05, fase: 0, titulo: El turno no se parte }\n"
            ."- { id: RF-ID-09, fase: 0, titulo: Revocar una credencial }\n",
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('RF-ID-09');
})->group('RQ-12', 'RNF-M-04');

it('detecta un requisito construido en una fase distinta a la del Anexo A', function (): void {
    // El caso de RN-11 y RN-12: estaban en la Fase 2 del Anexo A y se
    // implementaban en la 3. La fase decide cuando el requisito empieza a ser
    // exigible, asi que la divergencia desplaza el bloqueo sin que nadie lo vea.
    useDocsTree([
        'requisitos' => "- { id: RN-11, fase: 2, titulo: Descanso minimo entre jornadas }\n",
        'plan' => "| **Fase** | 3 — Cumplimiento |\n\n### Tarea 3.5 — Descansos\n\nCubre RN-11.\n",
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('RN-11');
})->group('RQ-12', 'RNF-M-04');

it('informa sin bloquear cuando se ejecuta sin --check', function (): void {
    // El modo de lectura sirve para mirar el estado sin romper nada; el que
    // bloquea es el de la CI, y son dos cosas distintas a proposito.
    useDocsTree([
        'requisitos' => "- { id: RF-ID-09, fase: 0, titulo: Revocar una credencial }\n",
    ]);

    [$exit, $output] = Commands::run('docs:consistency');

    expect($exit)->toBe(0);
    expect($output)->toContain('RF-ID-09');
})->group('RQ-12', 'RNF-M-04');
