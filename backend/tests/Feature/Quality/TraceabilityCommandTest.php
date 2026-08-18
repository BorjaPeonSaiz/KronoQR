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
})->group('RQ-13');
