<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
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
 * @param  array{requisitos?: string, adrRows?: string, adrFiles?: list<string>, adrContent?: string, plan?: string, contract?: string, controller?: string, code?: string, mustCite?: list<string>}  $overrides
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
        file_put_contents($docs.'/adr/'.$file, $overrides['adrContent'] ?? "# Decision\n");
    }

    file_put_contents($plan.'/00-fase-0.md', $overrides['plan'] ?? <<<'MD'
        | **Fase** | 0 — Cimientos |

        ### Tarea 0.1 — Levantar el entorno

        Cubre RN-05.
        MD);

    writeContractAndCode($root, $overrides);

    config([
        'quality.docs_path' => $docs,
        'quality.repo_path' => $root,
        'quality.plan_path' => 'plan implementacion',
        'quality.stack_file' => '02-stack.md',
        'quality.adr_path' => 'adr',
        'quality.requirements_file' => 'requisitos.yaml',
        'quality.contract_file' => 'openapi.yaml',
        'quality.requirement_citations.must_cite' => $overrides['mustCite'] ?? [$root.'/app/Modules/*/Http/Controller'],
        'quality.requirement_citations.must_exist' => [$root.'/app'],
    ]);
}

/**
 * Tarea 20.7: el contrato y el codigo del arbol de mentira, coherentes con el
 * catalogo por omision. Cada prueba rompe uno de los dos.
 *
 * @param  array{contract?: string, controller?: string, code?: string}  $overrides
 */
function writeContractAndCode(string $root, array $overrides): void
{
    file_put_contents($root.'/docs/openapi.yaml', $overrides['contract'] ?? <<<'YAML'
        openapi: 3.1.0
        paths:
          /api/v1/scan:
            post:
              operationId: recordScan
              summary: Registrar un escaneo
              description: El turno no se parte a medianoche (RN-05).
        YAML);

    $controllers = $root.'/app/Modules/Attendance/Http/Controller';
    mkdir($controllers, 0o777, true);

    file_put_contents($controllers.'/ScanController.php', $overrides['controller'] ?? "<?php\n\n/** Registra el escaneo (RN-05). */\nfinal class ScanController {}\n");
    file_put_contents($root.'/app/Modules/Attendance/WorkDay.php', $overrides['code'] ?? "<?php\n\n/** La jornada (RN-05). */\nfinal class WorkDay {}\n");
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/kronoqr-tree-*') ?: [] as $root) {
        (new Filesystem)->deleteDirectory($root);
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

it('detecta un indice que da por pendiente una decision que su ADR ya no tiene pendiente', function (): void {
    // V3-AR-1 y V21-3-AR-4: el §4 decia «implementacion pendiente» de ADR-057
    // cuando el ADR estaba implementado, y el comando no lo veia.
    useDocsTree([
        'adrRows' => '| **001** | **Monolito modular** *(aceptada; implementacion pendiente)* |',
        'adrContent' => "# Decision\n\n| Campo | Valor |\n|---|---|\n| **Estado** | Aceptada e implementada |\n",
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('ADR-001')->toContain('pendiente');
})->group('RQ-12', 'RNF-M-04');

it('detecta un ADR propuesto que el indice presenta como si estuviera en vigor', function (): void {
    useDocsTree([
        'adrRows' => '| **001** | **Monolito modular** *(aceptada)* |',
        'adrContent' => "# Decision\n\n| **Estado** | Propuesta, pendiente de decision del propietario |\n",
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('ADR-001')->toContain('propuesta');
})->group('RQ-12', 'RNF-M-04');

it('acepta un indice cuyo estado dice lo mismo que el ADR, aunque lo resuma', function (): void {
    useDocsTree([
        'adrRows' => '| **001** | **Monolito modular** *(aceptada; la prueba sigue pendiente)* |',
        'adrContent' => "# Decision\n\n| **Estado** | Aceptada. Pendiente: la prueba de arquitectura |\n",
    ]);

    expect(Commands::run('docs:consistency --check')[0])->toBe(0);
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

// --- Tarea 20.7: el orden «doc 01 -> contrato -> codigo» -------------------
//
// V21-6-AR-1/2/3 de la verificacion final de la 2.2.1: dos decisiones tardias
// llegaron al codigo y al contrato sin pasar por el doc 01, y el comando no vio
// ninguna. Cada prueba reproduce una forma de saltarse el orden que SI se puede
// ver de forma mecanica.

it('detecta una operacion del contrato que no cita ningun requisito', function (): void {
    useDocsTree([
        'contract' => <<<'YAML'
            openapi: 3.1.0
            paths:
              /api/v1/scan:
                post:
                  operationId: recordScan
                  description: El turno no se parte a medianoche (RN-05).
              /api/v1/kiosk/reload:
                post:
                  operationId: forceKioskReload
                  summary: Recarga la tablet a cualquier hora
                  description: Una excepcion que el doc 01 no recoge.
            YAML,
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('forceKioskReload')->toContain('POST /api/v1/kiosk/reload');
    expect($output)->not->toContain('recordScan');
})->group('RQ-12', 'RNF-M-04');

it('detecta un requisito citado por el contrato que el doc 01 no define', function (): void {
    // Tambien fuera de una operacion: en la descripcion de un esquema.
    useDocsTree([
        'contract' => <<<'YAML'
            openapi: 3.1.0
            paths:
              /api/v1/scan:
                post:
                  operationId: recordScan
                  description: El turno no se parte a medianoche (RN-05).
            components:
              schemas:
                Heartbeat:
                  description: La tablet atrasada recarga a cualquier hora (RF-KI-77).
            YAML,
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('RF-KI-77');
})->group('RQ-12', 'RNF-M-04');

it('no da por citada una operacion que solo nombra requisitos inexistentes', function (): void {
    // Citar algo que no existe no es citar: la operacion cuenta como sin
    // requisito, ademas de como cita inventada.
    useDocsTree([
        'contract' => <<<'YAML'
            openapi: 3.1.0
            paths:
              /api/v1/scan:
                post:
                  operationId: recordScan
                  description: Lo decide RN-99.
            YAML,
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('recordScan')->toContain('RN-99');
})->group('RQ-12', 'RNF-M-04');

it('detecta un controlador que no cita ningun requisito', function (): void {
    useDocsTree([
        'controller' => "<?php\n\n/** Recarga la tablet a cualquier hora. */\nfinal class ScanController {}\n",
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('app/Modules/Attendance/Http/Controller/ScanController.php');
})->group('RQ-12', 'RNF-M-04');

it('detecta codigo de produccion que cita un requisito que el doc 01 no define', function (): void {
    useDocsTree([
        'code' => "<?php\n\n/** Solo admin o rrhh firman la correccion (RN-05, RS-77). */\nfinal class WorkDay {}\n",
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('app/Modules/Attendance/WorkDay.php')->toContain('RS-77');
})->group('RQ-12', 'RNF-M-04');

it('no confunde un ejemplo ni una errata con un identificador', function (): void {
    // Sin falsos positivos: `RF-XX` y `RN-O5` (con la letra O) no son
    // identificadores del Anexo A, asi que ni cuentan como cita ni como cita
    // inventada. El controlador cita ademas RN-05, que si existe.
    useDocsTree([
        'controller' => "<?php\n\n/** Como RF-XX o RN-O5 en un ejemplo; lo decide RN-05. */\nfinal class ScanController {}\n",
        'code' => "<?php\n\n/** Un rango RN-05..09 cita su primer extremo. */\nfinal class WorkDay {}\n",
    ]);

    expect(Commands::run('docs:consistency --check')[0])->toBe(0);
})->group('RQ-12', 'RNF-M-04');

it('dice que no encuentra un directorio en vez de dar verde sin mirarlo', function (): void {
    // Una exploracion parcial que pasa no comprueba nada: si alguien mueve los
    // controladores, la configuracion tiene que moverse con ellos.
    useDocsTree([
        'mustCite' => [sys_get_temp_dir().'/kronoqr-tree-inexistente/app/Modules/*/Http/Controller'],
    ]);

    [$exit, $output] = Commands::run('docs:consistency --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('kronoqr-tree-inexistente');
})->group('RQ-12', 'RNF-M-04');
