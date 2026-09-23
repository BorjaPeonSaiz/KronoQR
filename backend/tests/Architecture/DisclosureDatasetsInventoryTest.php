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

    /*
     * Y los NOMINALES ACOTADOS, que son otra cosa y estan en las dos listas.
     *
     * `weekly_summary` (tarea 3.12) enumera los `employee_uuid` **solo cuando el
     * alcance tiene 50 personas o menos**; por encima, el asiento lleva
     * `employees`, `scope`, `manager_user_id` y `week_start` y ni un
     * identificador. Tratarlo como nominal a secas —como estaba— daba por hecho
     * que la consulta (a) del §4.1, la que filtra por persona, lo encuentra
     * siempre, y con un departamento de doscientas personas no encuentra nada:
     * quien acota el alcance de una brecha en el plazo del art. 33 creeria que
     * el resumen semanal de ese departamento no divulgo a nadie.
     *
     * Por eso tiene que estar **tambien** en el `IN (...)` de la consulta (b),
     * que es la que va por conjunto, y por eso no entra en la excepcion de
     * arriba.
     */
    $nominalesAcotados = ['weekly_summary'];

    expect(array_intersect($nominales, $nominalesAcotados))->toBe(
        [],
        'Un conjunto no puede ser nominal incondicional y acotado a la vez: o la consulta (a) lo encuentra siempre, o hace falta la (b).'
    );

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

    // Y los acotados, de los que el runbook tiene que decir ADEMAS cuando
    // enumeran y cuando no: sin esa frase, quien lee el asiento de un
    // departamento grande deduce que no habia nadie dentro.
    foreach ($nominalesAcotados as $dataset) {
        expect($enLaConsulta)->toContain("'".$dataset."'");

        // Y su fila tiene que decir el corte. `50` es el tope de
        // `GeneratePeriodReport::MAX_ENUMERATED_SUBJECTS`: si algun dia cambia,
        // esto se pone rojo y obliga a corregir el runbook a la vez que el
        // codigo, que es justo lo que una lista escrita a mano no hace sola.
        expect(str_contains(filaDelRunbook($runbook, $dataset), '50'))->toBeTrue(
            'La fila de «'.$dataset.'» en el §4.1 no dice a partir de cuantas personas deja de '
            .'enumerar a los afectados, y sin eso un asiento sin lista se lee como «no divulgo a nadie».'
        );
    }
})->group('RS-05', 'RL-15');

/**
 * La fila de la tabla del §4.1 que describe un conjunto, o cadena vacia.
 */
function filaDelRunbook(string $runbook, string $dataset): string
{
    foreach (explode("\n", $runbook) as $linea) {
        if (str_starts_with($linea, '| `'.$dataset.'` |')) {
            return $linea;
        }
    }

    return '';
}
