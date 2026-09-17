<?php

declare(strict_types=1);

use Tests\Feature\Quality\Support\Commands;
use Tests\Support\FakeTestSource;

/*
 * `qa:traceability --check` es la puerta de RQ-13: lo que bloquea la CI cuando
 * un requisito ya implementado no tiene prueba.
 *
 * Estas son las pruebas del SABOTAJE. Las de QualityGatesTest comprueban que la
 * puerta esta puesta —que el catalogo existe y que la etapa sigue en el
 * pipeline—; estas comprueban que SABE FALLAR, que es lo unico que la convierte
 * en puerta. Un `--check` que no puede ponerse rojo es un `--check` decorativo,
 * y durante toda la Fase 0 no hubo nada que lo descartara: un comentario decia
 * que este fichero existia, y no existia.
 */

/** Monta un catalogo temporal y apunta la configuracion a el. */
function useCatalog(string $yaml): string
{
    $docs = sys_get_temp_dir().'/kronoqr-docs-'.bin2hex(random_bytes(6));
    mkdir($docs, 0o777, true);
    file_put_contents($docs.'/requisitos.yaml', $yaml);

    config(['quality.docs_path' => $docs]);

    return $docs;
}

/** Monta una suite temporal y hace que el escaner mire solo ahi. */
function useTests(string $source = ''): string
{
    $root = sys_get_temp_dir().'/kronoqr-suite-'.bin2hex(random_bytes(6));
    mkdir($root, 0o777, true);

    if ($source !== '') {
        file_put_contents($root.'/CoberturaTest.php', $source);
    }

    config(['quality.test_paths' => ['pest' => [$root]]]);

    return $root;
}

/**
 * Lo mismo con la prueba de carga: un `scan-peak.js` temporal como unica fuente
 * de etiquetas. La de verdad vive en `load-tests/k6/` y no se toca desde aqui.
 */
function useLoadTest(string $source): string
{
    $root = sys_get_temp_dir().'/kronoqr-suite-'.bin2hex(random_bytes(6));
    mkdir($root, 0o777, true);
    file_put_contents($root.'/scan-peak.js', $source);

    config(['quality.test_paths' => ['k6' => [$root]]]);

    return $root;
}

afterEach(function (): void {
    foreach (['kronoqr-docs-*', 'kronoqr-suite-*'] as $pattern) {
        foreach (glob(sys_get_temp_dir().'/'.$pattern) ?: [] as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }
});

it('pasa cuando cada requisito de una fase ejecutada tiene prueba', function (): void {
    useCatalog("- { id: RN-05, fase: 0, titulo: El turno no se parte a medianoche }\n");
    useTests(FakeTestSource::file([FakeTestSource::pest('no parte el turno', ['RN-05'])]));
    config(['quality.current_phase' => 0]);

    expect(Commands::run('qa:traceability --check')[0])->toBe(0);
})->group('RQ-13');

it('bloquea cuando un requisito de una fase ejecutada no tiene ninguna prueba', function (): void {
    // El sabotaje. Si esto pasara en verde, la etapa ③b de la CI no comprobaria
    // nada y nadie se enteraria hasta una auditoria manual.
    useCatalog("- { id: RN-05, fase: 0, titulo: El turno no se parte a medianoche }\n");
    useTests();
    config(['quality.current_phase' => 0]);

    [$exit, $output] = Commands::run('qa:traceability --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('RN-05');
})->group('RQ-13');

it('no bloquea por requisitos de fases que todavia no se han ejecutado', function (): void {
    // Un bloqueo que salta por trabajo que nadie ha empezado se acaba
    // desactivando, y con el se va el que si sirve.
    useCatalog("- { id: RF-RE-01, fase: 3, titulo: Informe mensual }\n");
    useTests();
    config(['quality.current_phase' => 0]);

    expect(Commands::run('qa:traceability --check')[0])->toBe(0);
})->group('RQ-13');

it('respeta el orden real de ejecucion y no el numerico', function (): void {
    // Cerrada la Fase 5, la 3 sigue sin ejecutarse aunque su numero sea menor.
    useCatalog("- { id: RF-RE-01, fase: 3, titulo: Informe mensual }\n");
    useTests();
    config(['quality.current_phase' => 5]);

    expect(Commands::run('qa:traceability --check')[0])->toBe(0);
})->group('RQ-13');

it('NO da por cubierto un requisito cuya unica prueba esta saltada', function (): void {
    // El hueco que encontro el cierre de la Fase 0, de extremo a extremo. La
    // matriz se entrega como evidencia de que cada obligacion legal tiene una
    // prueba automatica que la verifica en cada cambio; una prueba saltada no
    // verifica nada, y RL-04 es conservacion del registro anterior.
    useCatalog("- { id: RL-04, fase: 0, titulo: Conservar la version anterior de una correccion }\n");
    useTests(FakeTestSource::file([
        FakeTestSource::pest('conserva el registro', ['RL-04'], chained: "->skip('pendiente de la 2.4')"),
    ]));
    config(['quality.current_phase' => 0]);

    [$exit, $output] = Commands::run('qa:traceability --check');

    expect($exit)->toBe(1);
    expect($output)->toContain('RL-04');
})->group('RQ-13', 'RL-04');

it('no bloquea por los requisitos que verifica una persona', function (): void {
    useCatalog("- { id: RQ-12, fase: 0, titulo: Definicion de Terminado, verificacion: revision }\n");
    useTests();
    config(['quality.current_phase' => 0]);

    expect(Commands::run('qa:traceability --check')[0])->toBe(0);
})->group('RQ-13');

it('avisa de las etiquetas que citan requisitos inexistentes', function (): void {
    // Una prueba etiquetada con un identificador que no esta en el catalogo
    // suele ser una errata, y la errata deja al requisito real sin cobertura.
    useCatalog("- { id: RN-05, fase: 0, titulo: El turno no se parte a medianoche }\n");
    useTests(FakeTestSource::file([
        FakeTestSource::pest('uno', ['RN-05']),
        FakeTestSource::pest('dos', ['RN-99']),
    ]));
    config(['quality.current_phase' => 0]);

    [$exit, $output] = Commands::run('qa:traceability --check');

    expect($exit)->toBe(0);
    expect($output)->toContain('RN-99');
})->group('RQ-13');

it('genera la matriz por la salida estandar sin escribir ficheros', function (): void {
    useCatalog("- { id: RN-05, fase: 0, titulo: El turno no se parte a medianoche }\n");
    useTests(FakeTestSource::file([FakeTestSource::pest('no parte el turno', ['RN-05'])]));
    config(['quality.current_phase' => 0]);

    [$exit, $output] = Commands::run('qa:traceability --output=-');

    expect($exit)->toBe(0);
    expect($output)->toContain('RN-05');
    // Y la entrada de Pest no arrastra ningun marcador: es el caso por defecto.
    expect($output)->toContain('` — no parte el turno |');
})->group('RQ-13');

it('da por cubierto un requisito cuya unica prueba es un escenario de k6', function (): void {
    // RNF-P-06 y RQ-08 no tienen ni pueden tener prueba de Pest: los mide la
    // prueba de carga, que dura minutos y necesita la pila levantada (§10.1).
    // Sin este tercer formato, `--check` bloquearia por dos requisitos que si
    // estan verificados, y la puerta que avisa de verdad se acaba desactivando.
    useCatalog("- { id: RNF-P-06, fase: 0, titulo: 50 fichajes por segundo en el cambio de turno }\n");
    useLoadTest(FakeTestSource::k6(['scan' => FakeTestSource::k6Requirements('RNF-P-06 RNF-P-02 RQ-08')]));
    config(['quality.current_phase' => 0]);

    // Etiquetada SOLO con RQ-13: esta prueba verifica el escaner, no el pico de
    // 50 fichajes/s. Citar aqui RNF-P-06 haria figurar en la matriz una prueba
    // de milisegundos como evidencia de un umbral de carga.
    expect(Commands::run('qa:traceability --check')[0])->toBe(0);
})->group('RQ-13');

it('enumera las pruebas de k6 en el alcance de la matriz', function (): void {
    // «Pest N, Playwright N, k6 N». La cifra es lo que vigila
    // TraceabilityMatrixFreshnessTest para que la matriz versionada no describa
    // un arbol que ya no existe.
    useCatalog("- { id: RNF-P-06, fase: 0, titulo: 50 fichajes por segundo en el cambio de turno }\n");
    useLoadTest(FakeTestSource::k6(['scan' => FakeTestSource::k6Requirements('RNF-P-06')]));
    config(['quality.current_phase' => 0]);

    [$exit, $output] = Commands::run('qa:traceability --output=-');

    expect($exit)->toBe(0);
    expect($output)->toContain('Pest 0, Playwright 0, k6 1');
    expect($output)->toContain('scan-peak.js:5` — scan');
})->group('RQ-13');

it('escribe en cada entrada cada cuanto se ejecuta esa prueba', function (): void {
    // La matriz se entrega como evidencia de RQ-13, y de k6 no es cierto que
    // «verifique en cada cambio»: la prueba de carga corre a mano y en la
    // etiqueta de cada version mayor. Quien lee la fila tiene que poder
    // distinguirlo sin salir de ella.
    useCatalog("- { id: RNF-P-06, fase: 0, titulo: 50 fichajes por segundo en el cambio de turno }\n");
    useLoadTest(FakeTestSource::k6(['scan' => FakeTestSource::k6Requirements('RNF-P-06')]));
    config(['quality.current_phase' => 0]);

    [, $output] = Commands::run('qa:traceability --output=-');

    // Solo se marca lo que se aparta del caso normal: Pest y Playwright corren
    // en cada push y no llevan nada, que es lo que mantiene la matriz legible.
    expect($output)->toContain('(k6: a mano y en cada etiqueta vX.0.0)');
    expect($output)->not->toContain('automática que la verifica en cada cambio');
})->group('RQ-13');

it('enumera aparte, y no como aviso, las pruebas condicionadas al entorno', function (): void {
    // Nueve pruebas del arbol dependen de una herramienta que no esta en todos
    // los entornos. Salian como «etiqueta con forma de requisito que no lo es»
    // y sus requisitos, sin cobertura.
    useCatalog("- { id: RF-IN-04, fase: 0, titulo: Sello del informe de periodo }\n");
    useTests(FakeTestSource::file([
        FakeTestSource::pest('sella el informe', ['RF-IN-04'], chained: "->skip(! hayChromium(), 'no esta instalado')"),
    ]));
    config(['quality.current_phase' => 0]);

    [$exit, $output] = Commands::run('qa:traceability --output=-');

    expect($exit)->toBe(0);
    expect($output)->toContain('## Pruebas condicionadas al entorno');
    expect($output)->toContain('sella el informe');
    expect($output)->toContain('si el entorno la deja correr');
    expect($output)->not->toContain('esta saltada y no cubre');
    expect($output)->not->toContain('etiqueta con forma de requisito que no lo es');
})->group('RQ-13');
