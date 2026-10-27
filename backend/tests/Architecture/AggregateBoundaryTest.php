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
 * Fuera quedan **solo** `WorkDay.php` y el fichero del tramo, no la carpeta
 * `Domain/Model` entera (V1-AR-3): una clase nueva junto a ellos no es parte
 * del agregado por vivir en la misma carpeta.
 *
 * @return list<string>
 */
function filesOutsideTheAggregate(): array
{
    $model = str_replace('\\', '/', ModuleTree::root()).'/Attendance/Domain/Model/';
    $aggregate = [$model.'WorkDay.php', $model.'Shift'.'Entry.php'];

    // Los dos recorridos van por `ModuleTree::phpFilesUnder()`, que usa
    // `scandir`: con `RecursiveDirectoryIterator` el bind mount de Docker
    // Desktop se comia ficheros enteros y esta prueba pasaba en verde sin
    // haberlos mirado, que es el modo de fallo peor de una regla de
    // arquitectura. Ver el docblock de `ModuleTree` y `SourceDiscoveryTest`.
    $files = [
        ...ModuleTree::filesIn(''),
        ...ModuleTree::phpFilesUnder(Repo::file('backend/tests')),
    ];

    $normalized = array_map(static fn (string $file): string => str_replace('\\', '/', $file), $files);

    return array_values(array_filter(
        $normalized,
        static fn (string $file): bool => ! \in_array($file, $aggregate, true),
    ));
}

/**
 * El fuente sin comentarios: un docblock que explique el atajo no lo usa, y sin
 * esto este mismo fichero no podria describir lo que busca.
 */
function aggregateCodeWithoutComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        $isComment = \is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true);
        $code .= $isComment ? ' ' : (\is_array($token) ? $token[1] : $token);
    }

    return $code;
}

/**
 * Si un fuente llama a `$method` del tramo saltandose la jornada.
 *
 * La llamada estatica cuenta siempre. La de instancia solo cuenta en un fichero
 * que ADEMAS nombra al tramo POR SU NOMBRE EXACTO o a la jornada. Los metodos
 * internos se llaman como se llama medio mundo —cerrar un escritor de ficheros,
 * abrir un cursor— y sin esta condicion la regla denuncia a cualquiera que
 * cierre un recurso: paso con el escritor de CSV de la exportacion legal (tarea
 * 1.17), que solo maneja `ExportedShiftEntry`, un objeto de lectura del modulo
 * Compliance que no es el tramo ni puede violar RN-01 ni RN-02. El limite de
 * palabra es lo que separa los dos casos: `ExportedShiftEntry` NO nombra al
 * tramo. Lo que se pierde es un falso positivo, no una garantia.
 *
 * **Cuenta tambien nombrar la jornada** (V1-AR-3). `entries()`, `openEntry()` y
 * `entry()` de la jornada devuelven los tramos vivos, asi que encadenar el
 * cierre detras de `openEntry()`, o anular cada tramo de un `foreach` sobre
 * `entries()`, tiene el tramo en la mano sin escribir su nombre, y salta RN-01,
 * RN-02, RN-07 y RN-08, el evento y el total. Quien maneja una jornada es quien
 * puede hacer eso.
 *
 * En un fichero que solo nombra la jornada no cuenta la llamada sobre un
 * colaborador inyectado (`$this->sealedPins` abre el sobre del PIN en el caso
 * de uso del fichaje con PIN): un tramo no se guarda en una propiedad de un
 * caso de uso. Si el fichero nombra al tramo, cuenta cualquier llamada.
 *
 * Los nombres se buscan en todo el fuente (un `@var` tambien nombra); las
 * llamadas, solo en el codigo, sin espacios ni tabuladores, para que una
 * llamada separada del operador de flecha no se escape por el formato.
 */
function aggregateInternalCallIn(string $source, string $method): bool
{
    $entry = 'Shift'.'Entry';
    $code = str_replace([' ', "\t"], '', aggregateCodeWithoutComments($source));
    $instanceCall = '->'.$method.'(';

    $mentionsEntry = preg_match('/(?<![A-Za-z0-9_])'.$entry.'(?![A-Za-z0-9_])/', $source) === 1;
    $mentionsWorkDay = preg_match('/(?<![A-Za-z0-9_])Work'.'Day(?![A-Za-z0-9_])/', $source) === 1;

    preg_match_all('/(?<!\$this)(?<!\$this\?)(\$this->\w+)?\??->'.preg_quote($method, '/').'\(/', $code, $receivers);
    $outsideCollaborators = array_filter($receivers[1], static fn (string $collaborator): bool => $collaborator === '');

    return str_contains($code, $entry.'::'.$method.'(')
        || ($mentionsEntry && str_contains($code, $instanceCall))
        || ($mentionsWorkDay && $outsideCollaborators !== []);
}

it('no llama desde fuera del agregado a los metodos internos del tramo', function (string $method): void {
    $offenders = [];

    foreach (filesOutsideTheAggregate() as $file) {
        if (aggregateInternalCallIn((string) file_get_contents($file), $method)) {
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

it('ve el atajo aunque el fichero no nombre al tramo (V1-AR-3)', function (string $codigo): void {
    // Las llamadas se componen: este fichero se explora a si mismo.
    $source = "<?php\nuse App\Modules\Attendance\Domain\Model\Work"."Day;\nfinal class Sonda {\n".$codigo."\n}\n";

    expect(aggregateInternalCallIn($source, 'close') || aggregateInternalCallIn($source, 'markVoided'))->toBeTrue();
})->with([
    'encadenado detras de openEntry' => ['public function f(WorkDay $workDay): void { $workDay->openEntry()?->'.'close($at, $origin); }'],
    'en un foreach sobre entries' => ['public function f(WorkDay $workDay): void { foreach ($workDay->entries() as $e) { $e->'.'markVoided(); } }'],
    'con la flecha en otra linea' => ["public function f(WorkDay \$workDay): void { \$workDay->entry(\$id)\n        ->".'close($at, $origin); }'],
])->group('RN-01', 'RN-02', 'RN-07', 'RN-08');

it('no confunde un colaborador ni un objeto de lectura con el tramo', function (string $codigo): void {
    $source = "<?php\nfinal class Sonda {\n".$codigo."\n}\n";

    expect(aggregateInternalCallIn($source, 'open') || aggregateInternalCallIn($source, 'close'))->toBeFalse();
})->with([
    'el sobre del PIN en un fichero que nombra la jornada' => ['/** WorkDay */ public function f(): void { $this->sealedPins->'.'open($pin); }'],
    'un escritor de CSV de la exportacion legal' => ['public function f(ExportedShiftEntry $row): void { $this->writer->'.'close(); $csv->'.'close(); }'],
    'un metodo propio en un fichero que nombra la jornada' => ['/** WorkDay */ public function f(): void { $this->'.'close(); }'],
])->group('RN-01', 'RN-02');
