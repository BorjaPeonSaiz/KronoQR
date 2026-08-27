<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * La frontera del agregado: `ShiftEntry` es una entidad DENTRO de `WorkDay`, no
 * una raiz.
 *
 * Quien protege RN-01 (un solo turno abierto) y RN-02 (sin solapes) es la
 * jornada. Un tramo abierto, cerrado o marcado por fuera deja esas dos
 * invariantes sin guardian, y el sintoma no aparece hasta que dos tramos
 * solapados llegan a una nomina.
 *
 * La clase lo documenta con `@internal`, pero **una anotacion no la verifica
 * ninguna herramienta**: PHPStan solo aplica `@internal` entre paquetes, no
 * dentro del propio. Asi que se comprueba aqui, sobre app/ y sobre tests/, que
 * es donde antes se cuela el atajo: una factoria de pruebas que abriera tramos
 * con `open()` dejaria la regla sin proteger y la suite en verde.
 *
 * La via legitima para rehidratar un tramo es `reconstitute()`, que es la que
 * usan el repositorio (tarea 1.4) y `ShiftEntryFactory`.
 *
 * Los nombres a buscar se COMPONEN en tiempo de ejecucion, nunca se escriben
 * enteros: este fichero tambien se explora a si mismo, y una llamada escrita
 * literal aqui se denunciaria sola. Es la misma gimnasia que hace
 * `Tests\Support\FakeTestSource` con las etiquetas de trazabilidad.
 */

/**
 * Los metodos que solo puede llamar WorkDay.
 *
 * Los tres primeros son del fichaje (tarea 1.1). Los cuatro siguientes, de la
 * correccion (tarea 1.15): dan de alta un tramo a mano, crean la version
 * siguiente y retiran la anterior, que es el mecanismo entero de RN-13. Llamar a
 * cualquiera de ellos desde fuera del agregado saltaria las invariantes que
 * protegen la version nueva —RN-01, RN-02, RN-05— y dejaria el total del dia
 * contando una version que ya no existe.
 */
const AGGREGATE_INTERNAL_METHODS = [
    'open',
    'close',
    'markAnomalous',
    'declaredManually',
    'nextVersion',
    'markSupersededBy',
    'markVoided',
];

/**
 * Ficheros PHP del backend que NO son el propio agregado: los de los modulos y
 * los de la suite de pruebas.
 *
 * @return list<string>
 */
function filesOutsideTheAggregate(): array
{
    $aggregate = str_replace('\\', '/', ModuleTree::root()).'/Attendance/Domain/Model';

    $files = [
        ...ModuleTree::filesIn(''),
        ...phpFilesUnder(Repo::file('backend/tests')),
    ];

    $normalized = array_map(static fn (string $file): string => str_replace('\\', '/', $file), $files);

    return array_values(array_filter(
        $normalized,
        static fn (string $file): bool => ! str_starts_with($file, $aggregate),
    ));
}

/**
 * @return list<string>
 */
function phpFilesUnder(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    ) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('no llama desde fuera del agregado a los metodos internos del tramo', function (string $method): void {
    $entry = 'Shift'.'Entry';
    $staticCall = $entry.'::'.$method.'(';
    $instanceCall = '->'.$method.'(';

    $offenders = [];

    foreach (filesOutsideTheAggregate() as $file) {
        // Sin espacios ni tabuladores, para que una llamada separada del
        // operador de flecha no se escape por el formato.
        $source = str_replace([' ', "\t"], '', (string) file_get_contents($file));

        // La llamada de instancia solo cuenta en un fichero que ADEMAS nombra al
        // tramo POR SU NOMBRE EXACTO. Los metodos internos se llaman como se
        // llama medio mundo —cerrar un escritor de ficheros, abrir un cursor— y
        // sin esta condicion la regla denuncia a cualquiera que cierre un
        // recurso: paso con el escritor de CSV de la exportacion legal (tarea
        // 1.17), que solo maneja `ExportedShiftEntry`, un objeto de lectura del
        // modulo Compliance que no es el tramo ni puede violar RN-01 ni RN-02.
        //
        // El limite de palabra es lo que separa los dos casos: `ExportedShiftEntry`
        // NO nombra al tramo, y un fichero que no lo nombra no puede tener uno
        // en la mano. Lo que se pierde es un falso positivo, no una garantia.
        $mentionsEntry = preg_match('/(?<![A-Za-z0-9_])'.$entry.'(?![A-Za-z0-9_])/', $source) === 1;

        $calls = str_contains($source, $staticCall)
            || ($mentionsEntry && str_contains($source, $instanceCall));

        if ($calls) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe([]);
})->with(AGGREGATE_INTERNAL_METHODS)->group('RN-01', 'RN-02');

it('marca como interno cada metodo que solo puede llamar la jornada', function (string $method): void {
    // La anotacion tiene que seguir ahi: es lo que le dice a quien lea la clase
    // por que existe la prueba de arriba.
    $source = (string) file_get_contents(ModuleTree::root().'/Attendance/Domain/Model/ShiftEntry.php');
    $signature = strpos($source, 'function '.$method.'(');

    expect($signature)->toBeInt();
    expect(substr($source, max(0, (int) $signature - 400), 400))->toContain('@internal');
})->with(AGGREGATE_INTERNAL_METHODS)->group('RN-01', 'RN-02');

it('rehidrata los tramos de prueba por la misma puerta que el repositorio', function (): void {
    // El positivo del mismo asunto: las factorias de dominio existen y entran
    // por `reconstitute()`. Sin esto, la prueba de arriba seguiria en verde el
    // dia que alguien borrase las factorias y construyese los tramos de otra
    // manera.
    $factory = (string) file_get_contents(Repo::file('backend/tests/Support/Factory/ShiftEntryFactory.php'));

    expect($factory)->toContain('Shift'.'Entry::reconstitute(');
})->group('RN-01');
