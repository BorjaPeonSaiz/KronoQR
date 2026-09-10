<?php

declare(strict_types=1);

use Tests\Architecture\Support\Doc82Series;
use Tests\Architecture\Support\Repo;

/*
 * LOS CINCO CUADROS DE MANDO DEL DOC 02 §8.3, VERSIONADOS COMO CODIGO
 * (tarea 3.2, decisiones 1, 2 y 13).
 *
 * ## Por que hay una prueba de arquitectura y no una herramienta
 *
 * No existe validador comunitario de cuadros de Grafana que merezca el coste
 * (decision 2). Lo que si existe es la forma concreta en la que estos cinco
 * ficheros se rompen sin que nadie se entere, y es siempre la misma: **el cuadro
 * carga y sale vacio**. Un `uid` de fuente de datos que no esta provisionada, un
 * nombre de serie que se escribio de memoria, un `id` de panel repetido — nada
 * de eso lanza ningun error. Grafana pinta el marco, deja el area en blanco y
 * quien mira concluye que no hay trafico.
 *
 * Un cuadro vacio es peor que ningun cuadro: el de operacion de quioscos es el
 * que responde «¿esta fichando la gente?» y el de impacto y adopcion es el que
 * sostiene la renovacion de la licencia (RF-IN-08).
 *
 * ## Las tres afirmaciones que no son de forma
 *
 *   · **Cada nombre de serie de cada consulta existe en el §8.2.** Es la unica
 *     forma de que un cuadro no prometa un dato que nadie emite. La lista de
 *     series la da el DOCUMENTO, no una copia escrita aqui.
 *   · **Ninguna consulta agrupa por una etiqueta que identifique a una persona**
 *     (regla dura 21). Grafana no es el panel del producto: no tiene control de
 *     acceso por rol del hotel y quien entra ve todo lo que hay dentro.
 *   · **Ningun titulo lleva el nombre de un cliente** (regla dura 13, ADR-017).
 *     El dia que un cuadro diga «Hotel …», vender el producto a otro cliente
 *     exige tocar el repositorio.
 *
 * ## Lo que se deja fuera a proposito
 *
 * Las consultas de las variables de plantilla (`templating.list[].query`) no se
 * comprueban contra el §8.2: un `label_values(serie, device)` mezcla el nombre
 * de la serie con el de una etiqueta y distinguirlos exigiria un analizador de
 * PromQL de verdad. Lo que si se comprueba de ellas es la fuente de datos.
 */

/** Los cinco cuadros de la decision 1: fichero, uid y titulo. */
const CUADROS_DE_MANDO = [
    'operacion-quioscos.json' => ['kronoqr-kiosks', 'Operación de quioscos'],
    'salud-api.json' => ['kronoqr-api', 'Salud de la API'],
    'integridad-dato.json' => ['kronoqr-integrity', 'Integridad del dato'],
    'negocio.json' => ['kronoqr-business', 'Negocio'],
    'impacto-adopcion.json' => ['kronoqr-adoption', 'Impacto y adopción'],
];

/** Las tres fuentes provisionadas, y ninguna mas (decision 1). */
const FUENTES_PROVISIONADAS = ['kronoqr-prometheus', 'kronoqr-loki', 'kronoqr-tempo'];

/**
 * Series que no salen del §8.2 y que las consultas si pueden usar: las del
 * anfitrion, las de la sonda del borde y la marca del actualizador.
 *
 * Lista corta y explicita: cada nombre de aqui es una serie que emite algo que
 * no es la aplicacion, y ampliarla tiene que costar una decision.
 *
 * @var list<string>
 */
const SERIES_EXTERNAS = [
    'node_filesystem_avail_bytes',
    'node_filesystem_size_bytes',
    'probe_success',
    'probe_duration_seconds',
    'probe_ssl_earliest_cert_expiry',
    'up',
    'kronoqr_maintenance_active',
    'kronoqr_maintenance_since_timestamp_seconds',
];

/**
 * Palabras de PromQL que no son nombres de serie.
 *
 * Las funciones se filtran por su forma —van seguidas de `(`— y no por lista;
 * aqui solo estan los operadores de agregacion y las palabras clave, que
 * aparecen sueltas.
 *
 * @var list<string>
 */
const PALABRAS_DE_PROMQL = [
    'sum', 'avg', 'min', 'max', 'count', 'group', 'stddev', 'stdvar', 'topk', 'bottomk', 'quantile',
    'count_values', 'by', 'without', 'on', 'ignoring', 'group_left', 'group_right', 'and', 'or',
    'unless', 'bool', 'offset', 'le', 'inf', 'nan', 'start', 'end', 'step',
];

/** Etiquetas que identifican a una persona y que no pueden entrar en una consulta. */
const ETIQUETAS_PROHIBIDAS = ['employee_name', 'name', 'email', 'national_id'];

/**
 * Un cuadro de mando ya decodificado.
 *
 * @return array<string, mixed>
 */
function cuadroDeMando(string $fichero): array
{
    // En la SUBCARPETA `KronoQR/` y no sueltos en la raiz de `path`: con
    // `foldersFromFilesStructure: true`, Grafana 11.5 ignora el campo `folder`
    // del proveedor y deja en «General» todo lo que encuentra en la raiz. El
    // nombre de la subcarpeta ES el nombre de la carpeta de Grafana, que la
    // decision 1 exige que sea «KronoQR».
    $ruta = Repo::file('infra/observability/grafana/dashboards/KronoQR/'.$fichero);

    expect(is_file($ruta))->toBeTrue(
        'Falta el cuadro '.$fichero.', que el doc 02 §8.3 publica como parte del producto.'
    );

    $decodificado = json_decode((string) file_get_contents($ruta), true);

    expect($decodificado)->toBeArray($fichero.' no es JSON valido: Grafana no lo cargaria.');

    /** @var array<string, mixed> $decodificado */
    return $decodificado;
}

/**
 * Un valor cualquiera del JSON leido como texto.
 *
 * El JSON de Grafana es libre —cada tipo de panel escribe lo que quiere— y su
 * decodificacion es `mixed` de arriba abajo. Estos tres lectores son lo que
 * permite que el analisis estatico siga en nivel 9 sin sembrar el fichero de
 * conversiones: un campo que no tiene el tipo esperado se lee como vacio y la
 * afirmacion que lo use fallara diciendo que falta, no reventara.
 */
function textoDelCuadro(mixed $valor): string
{
    return \is_string($valor) ? $valor : '';
}

/** Un valor cualquiera del JSON leido como entero. */
function enteroDelCuadro(mixed $valor): int
{
    return \is_int($valor) ? $valor : 0;
}

/** El `uid` de la fuente de datos declarada en un nodo del JSON. */
function fuenteDelNodo(mixed $nodo): string
{
    $fuente = \is_array($nodo) ? ($nodo['datasource'] ?? null) : null;

    return \is_array($fuente) ? textoDelCuadro($fuente['uid'] ?? null) : '';
}

/**
 * Todos los paneles del cuadro, incluidos los anidados dentro de una fila.
 *
 * @param  array<string, mixed>  $cuadro
 * @return list<array<string, mixed>>
 */
function panelesDe(array $cuadro): array
{
    /** @var list<array<string, mixed>> $pendientes */
    $pendientes = \is_array($cuadro['panels'] ?? null) ? array_values($cuadro['panels']) : [];
    $paneles = [];

    while ($pendientes !== []) {
        $panel = array_shift($pendientes);
        $paneles[] = $panel;

        /** @var list<array<string, mixed>> $anidados */
        $anidados = \is_array($panel['panels'] ?? null) ? array_values($panel['panels']) : [];
        $pendientes = array_merge($pendientes, $anidados);
    }

    return $paneles;
}

/**
 * Las consultas PromQL del cuadro: las de los objetivos servidos por Prometheus.
 *
 * @param  array<string, mixed>  $cuadro
 * @return list<string>
 */
function consultasPromQlDe(array $cuadro): array
{
    $consultas = [];

    foreach (panelesDe($cuadro) as $panel) {
        $fuenteDelPanel = fuenteDelNodo($panel);

        /** @var list<array<string, mixed>> $objetivos */
        $objetivos = \is_array($panel['targets'] ?? null) ? array_values($panel['targets']) : [];

        foreach ($objetivos as $objetivo) {
            $fuente = fuenteDelNodo($objetivo) !== '' ? fuenteDelNodo($objetivo) : $fuenteDelPanel;
            $expresion = textoDelCuadro($objetivo['expr'] ?? null);

            if ($fuente === 'kronoqr-prometheus' && $expresion !== '') {
                $consultas[] = $expresion;
            }
        }
    }

    return $consultas;
}

/**
 * Los identificadores que una consulta usa como nombre de serie.
 *
 * Se quitan primero las cadenas literales, los emparejadores de etiqueta y las
 * agrupaciones `by (...)`, que es donde viven los nombres de ETIQUETA; lo que
 * queda solo puede ser un nombre de serie, una funcion —que va seguida de `(`— o
 * una palabra clave.
 *
 * @return list<string>
 */
function seriesUsadasEn(string $expresion): array
{
    $limpia = (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '', $expresion);
    $limpia = (string) preg_replace('/\{[^}]*\}/', '{}', $limpia);
    $limpia = (string) preg_replace('/\b(by|without|on|ignoring|group_left|group_right)\s*\([^)]*\)/', ' ', $limpia);

    preg_match_all('/(?<![a-zA-Z0-9_:$])([a-z_][a-z0-9_]*)\s*(\(?)/', $limpia, $coincidencias, PREG_SET_ORDER);

    $series = [];

    foreach ($coincidencias as $coincidencia) {
        if ($coincidencia[2] === '(' || \in_array($coincidencia[1], PALABRAS_DE_PROMQL, true)) {
            continue;
        }

        $series[] = $coincidencia[1];
    }

    return array_values(array_unique($series));
}

/**
 * Los nombres de serie del bloque literal del doc 02 §8.2, con las derivadas de
 * cada histograma.
 *
 * El parseo lo hace `Support\Doc82Series`, compartido con `MetricsCatalogueTest`
 * desde la decision 17k: eran dos copias de la misma expresion regular y una
 * copia se corrige en un sitio y se queda vieja en el otro. Aqui solo queda la
 * guarda: si el bloque deja de parsearse, esta prueba compararia contra el vacio
 * y daria por buena cualquier serie inventada.
 *
 * @return list<string>
 */
function seriesDelDocumento(): array
{
    $series = Doc82Series::names();

    expect(\count($series))->toBeGreaterThan(40, 'El bloque de series del §8.2 ha dejado de parsearse.');

    return $series;
}

it('publica los cinco cuadros del §8.3 con su uid y su titulo', function (string $fichero, string $uid, string $titulo): void {
    // El `uid` es la direccion permanente del cuadro: los enlaces de los
    // runbooks y del panel apuntan a el. Si cambia, los enlaces siguen
    // existiendo y llevan a un 404 — que es como se descubre, el peor dia, que
    // el procedimiento remite a un sitio que ya no esta.
    $cuadro = cuadroDeMando($fichero);

    expect($cuadro['uid'] ?? '')->toBe($uid);

    // Termina en el nombre del §8.3; el prefijo del producto («KronoQR · ») es
    // presentacion y queda libre. Lo que se ata es la correspondencia con la
    // fila del documento: un cuadro llamado «Quioscos» a secas se puede
    // confundir con el panel de salud de quioscos de la 3.3, que es otra cosa.
    expect(str_ends_with(textoDelCuadro($cuadro['title'] ?? null), $titulo))->toBeTrue(
        $fichero.' se titula «'.textoDelCuadro($cuadro['title'] ?? null).'» y el §8.3 lo llama «'.$titulo.'».'
    );
})->with([
    'operacion de quioscos' => ['operacion-quioscos.json', 'kronoqr-kiosks', 'Operación de quioscos'],
    'salud de la API' => ['salud-api.json', 'kronoqr-api', 'Salud de la API'],
    'integridad del dato' => ['integridad-dato.json', 'kronoqr-integrity', 'Integridad del dato'],
    'negocio' => ['negocio.json', 'kronoqr-business', 'Negocio'],
    'impacto y adopcion' => ['impacto-adopcion.json', 'kronoqr-adoption', 'Impacto y adopción'],
])->group('RF-IN-08');

it('nombra los cinco cuadros exactamente como los publica el doc 02 §8.3', function (): void {
    // La tarea nacio con una contradiccion —el §11 decia cuatro cuadros y el
    // §8.3 lista cinco— y se resolvio a favor del §8.3. Esta prueba es lo que
    // impide que la contradiccion vuelva por el otro lado: un cuadro renombrado
    // en el repositorio y el documento diciendo otra cosa.
    $documento = Repo::contents('docs/02-stack-tecnologico-y-plan-implementacion.md');

    $seccion = (string) strstr((string) strstr($documento, '### 8.3 Cuadros de mando'), "\n### 8.4", true);

    preg_match_all('/^\| \*\*([^*]+)\*\* \|/mu', $seccion, $coincidencias);

    expect($coincidencias[1])->toBe(array_values(array_map(
        static fn (array $cuadro): string => $cuadro[1],
        CUADROS_DE_MANDO,
    )));
})->group('RF-IN-08');

it('deja los cinco cuadros como codigo, sin edicion desde la interfaz ni identificador de importacion', function (string $fichero): void {
    // `editable: false` mas `allowUiUpdates: false` en el proveedor: los cuadros
    // se editan en el repositorio. Sin las dos mitades, un cambio hecho en la
    // interfaz sobrevive hasta el siguiente despliegue y desaparece sin avisar,
    // y nadie sabe si lo que ve es lo que hay versionado.
    //
    // `id: null` es la otra condicion del aprovisionamiento por fichero: con un
    // `id` numerico dentro, Grafana intenta casarlo con el de su base y falla en
    // cuanto la instalacion tiene otro cuadro con ese numero.
    $cuadro = cuadroDeMando($fichero);

    expect($cuadro['editable'] ?? null)->toBeFalse();
    // `array_key_exists` y no `??`: con el operador, un `id` presente y nulo y un
    // `id` ausente dan el mismo resultado, y son casos distintos.
    expect(\array_key_exists('id', $cuadro) && $cuadro['id'] === null)->toBeTrue(
        $fichero.' tiene que declarar "id": null para que Grafana lo aprovisione por fichero.'
    );
})->with(array_keys(CUADROS_DE_MANDO))->group('RF-IN-08');

it('no repite el identificador de ningun panel dentro de un cuadro', function (string $fichero): void {
    // Con dos paneles del mismo `id`, Grafana carga uno y descarta el otro sin
    // decir nada: el cuadro se abre, parece completo y le falta un panel. Es el
    // fallo tipico de copiar un panel a mano, que es como se editan estos
    // ficheros.
    $identificadores = array_map(
        static fn (array $panel): int => enteroDelCuadro($panel['id'] ?? null),
        panelesDe(cuadroDeMando($fichero)),
    );

    expect($identificadores)->toBe(array_values(array_unique($identificadores)));
})->with(array_keys(CUADROS_DE_MANDO))->group('RF-IN-08');

it('solo consulta fuentes de datos que el aprovisionamiento declara', function (string $fichero): void {
    // Un `uid` de fuente que no existe deja el panel vacio con un aviso
    // discreto. Los tres provisionados son los del fichero de fuentes; cualquier
    // otro es un nombre escrito de memoria o copiado de otra instalacion.
    preg_match_all(
        '/"datasource"\s*:\s*\{[^}]*"uid"\s*:\s*"([^"]+)"/',
        json_encode(panelesDe(cuadroDeMando($fichero)), JSON_UNESCAPED_UNICODE) ?: '',
        $coincidencias,
    );

    $ajenas = array_values(array_unique(array_diff($coincidencias[1], FUENTES_PROVISIONADAS)));

    expect($ajenas)->toBe([], $fichero.' consulta fuentes de datos que no estan provisionadas.');
})->with(array_keys(CUADROS_DE_MANDO))->group('RF-IN-08');

it('no grafica ninguna serie que no exista en el catalogo del §8.2', function (string $fichero): void {
    // ESTA es la afirmacion que sostiene el resto. Un nombre de serie mal
    // escrito produce un panel vacio, y un panel vacio se lee como «no ha pasado
    // nada», que es la conclusion contraria a la verdadera. Se compara contra el
    // bloque literal del §8.2 —la fuente— mas las series que emiten el
    // anfitrion, la sonda del borde y el actualizador.
    $permitidas = array_merge(seriesDelDocumento(), SERIES_EXTERNAS);
    $consultas = consultasPromQlDe(cuadroDeMando($fichero));
    $desconocidas = [];

    // Sin esta linea, un cuadro cuyos objetivos no declaren fuente de datos
    // pasaria esta prueba sin haber comprobado ni una consulta.
    expect($consultas)->not->toBeEmpty(
        $fichero.' no tiene ninguna consulta servida por kronoqr-prometheus: '
        .'o esta vacio, o sus objetivos no declaran la fuente de datos.'
    );

    foreach ($consultas as $consulta) {
        foreach (array_diff(seriesUsadasEn($consulta), $permitidas) as $serie) {
            $desconocidas[] = $serie.' — en «'.$consulta.'»';
        }
    }

    expect(array_values(array_unique($desconocidas)))->toBe([]);
})->with(array_keys(CUADROS_DE_MANDO))->group('RF-IN-08');

it('no agrupa ni filtra por ninguna etiqueta que identifique a una persona', function (string $fichero): void {
    // REGLA DURA 21. Grafana lo ve quien tiene la contraseña de Grafana, que no
    // es el control de acceso por rol del producto: un cuadro que reparta horas
    // por nombre convierte la observabilidad en un panel de control de personas
    // accesible para quien opera el servidor. Se identifica por `device` y, a lo
    // sumo, por `employee_uuid`.
    $encontradas = [];

    foreach (consultasPromQlDe(cuadroDeMando($fichero)) as $consulta) {
        foreach (ETIQUETAS_PROHIBIDAS as $etiqueta) {
            if (preg_match('/\b'.$etiqueta.'\b/', $consulta) === 1) {
                $encontradas[] = $etiqueta.' — en «'.$consulta.'»';
            }
        }
    }

    expect($encontradas)->toBe([]);
})->with(array_keys(CUADROS_DE_MANDO))->group('RL-08');

it('no lleva el nombre de ningun cliente en el titulo de ningun panel', function (string $fichero): void {
    // REGLA DURA 13 y ADR-017: nada especifico de un cliente vive en el
    // repositorio. Un «Hotel …» en el titulo de un panel obliga a tocar el
    // codigo para vender la siguiente instalacion, que es la definicion exacta
    // de lo que ese ADR prohibe.
    $titulos = array_map(
        static fn (array $panel): string => textoDelCuadro($panel['title'] ?? null),
        panelesDe(cuadroDeMando($fichero)),
    );

    $titulos[] = textoDelCuadro(cuadroDeMando($fichero)['title'] ?? null);

    $conMarca = array_values(array_filter(
        $titulos,
        static fn (string $titulo): bool => str_contains($titulo, 'Hotel'),
    ));

    expect($conMarca)->toBe([]);
})->with(array_keys(CUADROS_DE_MANDO))->group('RF-PD-01');

it('mantiene el proveedor que carga los cuadros sin permitir edicion desde la interfaz', function (): void {
    // La otra mitad de `editable: false`. Con `allowUiUpdates: true`, Grafana
    // escribe encima del fichero provisionado y el repositorio deja de ser la
    // fuente: dos versiones del mismo cuadro y ninguna forma de saber cual se
    // esta mirando.
    expect(Repo::contents('infra/observability/grafana/provisioning/dashboards/dashboards.yaml'))
        ->toMatch('/^\s*allowUiUpdates:\s*false\s*$/m');
})->group('RF-IN-08');
