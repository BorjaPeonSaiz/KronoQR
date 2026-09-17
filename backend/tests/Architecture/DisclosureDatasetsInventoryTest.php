<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * **Todo conjunto de datos personales que el producto divulga esta descrito en el
 * runbook de brecha de seguridad** (RS-05, RL-15, RGPD art. 33).
 *
 * ## Que se rompe cuando esto no se comprueba
 *
 * `docs/runbooks/brecha-de-seguridad.md` §4.1 lleva la consulta con la que se
 * acota el alcance de una brecha —un `IN (...)` de conjuntos— y la tabla «los
 * conjuntos son estos y no hay mas». Las dos son **listas escritas a mano**, y una
 * lista escrita a mano se queda atras: la tarea que añade una pantalla nueva
 * escribe su `recordDisclosure('lo_que_sea', …)`, la pantalla se audita
 * correctamente, y el conjunto no aparece en el runbook.
 *
 * El dia que haga falta, nadie lo nota. La consulta del §4.1 devuelve filas —solo
 * que no las de ese conjunto— y quien esta calculando el alcance de una brecha en
 * el plazo de 72 horas del art. 33 cree que ya las ha visto todas. **Notificar de
 * menos por una lista desactualizada es notificar mal**, y la lista la mira una
 * persona bajo presion a las tres de la mañana.
 *
 * Es el mismo argumento que `MetricsCatalogueTest` con el §8.2: un documento que
 * enumera lo que el codigo hace tiene que romperse cuando el codigo cambia.
 *
 * ## La comprobacion es en UNA direccion, y a proposito
 *
 * Todo conjunto del codigo tiene que estar en el runbook; **no** al reves. El
 * runbook puede nombrar conjuntos que hoy no emite nadie —un asiento antiguo
 * conservado cuatro anos por RL-02 sigue estando en la tabla aunque la pantalla
 * que lo producia ya no exista—, y exigir lo contrario obligaria a borrar del
 * runbook justo lo que hace falta para leer el historico.
 *
 * ## El recorrido va con `scandir`
 *
 * {@see ModuleTree::phpFilesUnder()} y no `RecursiveDirectoryIterator`: sobre el
 * *bind mount* de Docker Desktop el iterador pierde ficheros en silencio, y una
 * prueba que no ve el fichero da verde sin haber comprobado nada. Ver
 * `SourceDiscoveryTest`.
 */

/**
 * Los `dataset` que el codigo divulga de verdad.
 *
 * Se derivan de dos formas porque el producto usa las dos: la constante
 * `DATASET` de cada caso de uso —que es la convencion— y el literal suelto del
 * padron del quiosco. Buscar solo una dejaria fuera la otra sin avisar.
 *
 * @return list<string>
 */
function conjuntosDivulgadosPorElCodigo(): array
{
    $datasets = [];

    foreach (ModuleTree::filesIn('') as $file) {
        $source = (string) file_get_contents($file);

        // a) `private const string DATASET = 'x';`
        if (preg_match('/const\s+string\s+DATASET\s*=\s*\'([a-z0-9_]+)\'/', $source, $match) === 1) {
            $datasets[] = $match[1];
        }

        // b) `->recordDisclosure('x', …)` con el nombre escrito en el sitio.
        if (preg_match_all('/recordDisclosure\(\s*\'([a-z0-9_]+)\'/', $source, $matches) > 0) {
            foreach ($matches[1] as $literal) {
                $datasets[] = $literal;
            }
        }
    }

    $datasets = array_values(array_unique($datasets));

    sort($datasets);

    return $datasets;
}

it('encuentra los conjuntos divulgados, para que la comparacion signifique algo', function (): void {
    // Red de seguridad de la propia prueba: si los dos patrones dejaran de casar
    // —alguien renombra la constante, alguien compone el nombre—, la comparacion
    // de abajo pasaria comparando un conjunto vacio contra el runbook.
    $datasets = conjuntosDivulgadosPorElCodigo();

    expect(\count($datasets))->toBeGreaterThan(7)
        // Los dos extremos de la derivacion: uno por constante y el unico literal.
        ->and($datasets)->toContain('employee_workdays')
        ->and($datasets)->toContain('kiosk_roster');
})->group('RS-05', 'RL-15');

it('nombra en el runbook de brecha todos los conjuntos que el codigo divulga', function (): void {
    $runbook = Repo::contents('docs/runbooks/brecha-de-seguridad.md');

    // Con su FILA, no con una mencion de pasada: lo que se lee a las tres de la
    // mañana es la tabla, y un conjunto nombrado solo en un parrafo no dice ni
    // que se divulga ni si el payload nombra a alguien.
    $ausentes = array_values(array_filter(
        conjuntosDivulgadosPorElCodigo(),
        static fn (string $dataset): bool => ! str_contains($runbook, '| `'.$dataset.'` |'),
    ));

    expect($ausentes)->toBe(
        [],
        'Estos conjuntos se divulgan y el runbook de brecha no los describe: '
        .implode(', ', $ausentes).'. Sin su fila en la tabla de §4.1, quien acota el '
        .'alcance de una brecha en el plazo del art. 33 no sabe que existen.'
    );
})->group('RS-05', 'RL-15');

it('los incluye tambien en la consulta que acota el alcance por bloque', function (): void {
    /*
     * La tabla explica; la consulta es la que se copia y se ejecuta. Un conjunto
     * documentado en la tabla pero ausente del `IN (...)` produce el fallo mas
     * silencioso de todos: la consulta devuelve filas, nadie ve un error, y
     * faltan precisamente las de la pantalla nueva.
     *
     * Se exceptuan los conjuntos NOMINALES —los que llevan el identificador de la
     * persona en el payload— porque esos se resuelven en la consulta (a) del
     * mismo apartado, que filtra por `employee_uuid` y no por nombre de conjunto.
     */
    $nominales = ['employee_workdays', 'incident_digest', 'incident'];

    $runbook = Repo::contents('docs/runbooks/brecha-de-seguridad.md');

    // El bloque del `IN (...)` de la consulta (b) de §4.1, tal cual esta escrito.
    preg_match("/payload->>'dataset' IN \((.*?)\)/s", $runbook, $match);

    $enLaConsulta = $match[1] ?? '';

    expect($enLaConsulta)->not->toBe('', 'El §4.1 del runbook ya no tiene la consulta por conjunto.');

    $ausentes = array_values(array_filter(
        conjuntosDivulgadosPorElCodigo(),
        static fn (string $dataset): bool => ! \in_array($dataset, $nominales, true)
            && ! str_contains($enLaConsulta, "'".$dataset."'"),
    ));

    expect($ausentes)->toBe(
        [],
        'Estos conjuntos se divulgan en bloque y la consulta de §4.1 no los busca: '
        .implode(', ', $ausentes).'. Quien la ejecute vera filas y creera que las ha visto todas.'
    );
})->group('RS-05', 'RL-15');
