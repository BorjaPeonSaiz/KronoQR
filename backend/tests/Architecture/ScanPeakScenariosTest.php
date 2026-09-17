<?php

declare(strict_types=1);

/*
 * El guion de la prueba de carga y su lanzador, leidos como fuente (RQ-13,
 * RNF-P-06, RQ-08, tarea 3.6).
 *
 * POR QUE HACE FALTA UNA PRUEBA SOBRE UN FICHERO DE K6. La prueba de carga no
 * corre en la CI —dura minutos y necesita la pila levantada (§10.1)— y su
 * cobertura de requisitos se lee del FUENTE, igual que la de Playwright. Eso
 * abre un hueco concreto: un escenario puede quedarse con `rate: 0`, sin rol que
 * lo lance o apuntando a una funcion que ya no existe, y **seguir apareciendo en
 * la matriz de trazabilidad como cobertura de su requisito**. RNF-P-06 constaria
 * como probado por un escenario que no se ejecuta nunca. Esto es lo que impide
 * esa mentira.
 *
 * LO QUE SE COMPRUEBA, Y NADA MAS. No se valida que la carga sea correcta —eso
 * lo dice el veredicto de la pasada— sino que el guion esta VIVO: los cinco
 * escenarios existen, piden trafico, alguien los lanza y su `exec` apunta a
 * codigo de verdad. Y que `run.sh` lanza k6 con el uid del invocante, porque sin
 * eso el contenedor no puede escribir su CSV en un runner de Linux y la pasada
 * entera se queda sin muestras sin que nada falle.
 */

/** Los cinco escenarios de la decision 2 de la ficha 3.6, con su requisito. */
const ESCENARIOS_DE_CARGA = ['scan', 'resend', 'reject', 'batch', 'compliance'];

function fuenteDeCarga(string $fichero): string
{
    // SIN FACADES: la suite Architecture corre sobre PHPUnit puro y no arranca el
    // framework, asi que `config('quality.repo_path')` no existe aqui. Se
    // resuelve igual que en `config/quality.php`: dentro del contenedor el
    // repositorio viene por un montaje aparte de solo lectura —`backend/` esta en
    // otro sitio y `dirname(__DIR__, 3)` daria `/var/www`— y fuera es el
    // directorio padre de `backend/`.
    $raiz = getenv('REPO_PATH');

    if (! is_string($raiz) || $raiz === '') {
        $raiz = is_dir('/var/www/repo') ? '/var/www/repo' : dirname(__DIR__, 3);
    }

    $ruta = rtrim($raiz, '/').'/load-tests/k6/'.$fichero;

    expect(is_readable($ruta))->toBeTrue('No se encuentra '.$ruta);

    return (string) file_get_contents($ruta);
}

/**
 * El cuerpo de `nombre: { ... }` dentro del objeto de escenarios.
 *
 * Se recorta desde la clave hasta la linea que cierra al mismo nivel de
 * indentacion, que es como esta escrito el fichero.
 */
function escenarioDeCarga(string $fuente, string $nombre): string
{
    $inicio = strpos($fuente, "\n  {$nombre}: {");

    expect($inicio)->not->toBeFalse('El escenario «'.$nombre.'» no existe en scan-peak.js');

    $fin = strpos($fuente, "\n  },", (int) $inicio);

    expect($fin)->not->toBeFalse('El escenario «'.$nombre.'» no cierra');

    return substr($fuente, (int) $inicio, (int) $fin - (int) $inicio);
}

/**
 * El primer grupo de captura del patron, exigiendo que el patron case.
 *
 * Existe para que la prueba diga QUE falta cuando el guion cambia de forma —«el
 * escenario no declara rate»— en lugar de reventar con un aviso de indice
 * inexistente tres lineas mas abajo.
 */
function capturaDeCarga(string $patron, string $sujeto, string $mensaje): string
{
    $encontrado = [];

    expect(preg_match($patron, $sujeto, $encontrado))->toBe(1, $mensaje);

    $valor = $encontrado[1] ?? null;

    return is_string($valor) ? $valor : '';
}

/**
 * La tasa declarada, resolviendo la constante si la tasa es un identificador.
 *
 * `rate: SCAN_RATE` vale tanto como `rate: 6` mientras la constante tenga un
 * valor por omision positivo; lo que no puede pasar desapercibido es un cero.
 */
function tasaDeCarga(string $fuente, string $escenario): int
{
    $declarada = capturaDeCarga('/\brate:\s*([A-Za-z_0-9]+)/', $escenario, 'El escenario no declara `rate`');

    if (is_numeric($declarada)) {
        return (int) $declarada;
    }

    return (int) capturaDeCarga(
        '/const '.preg_quote($declarada, '/').'\s*=.*\|\|\s*(\d+)\s*\)/',
        $fuente,
        'La tasa «'.$declarada.'» no tiene valor por omision',
    );
}

it('declara los cinco escenarios de la prueba de carga y todos piden trafico', function (string $nombre): void {
    // Un escenario a cero sigue saliendo en la matriz de trazabilidad como
    // cobertura de su requisito, y no ejecuta ni una peticion.
    $fuente = fuenteDeCarga('scan-peak.js');

    expect(tasaDeCarga($fuente, escenarioDeCarga($fuente, $nombre)))->toBeGreaterThan(0);
})->with(ESCENARIOS_DE_CARGA)->group('RQ-13', 'RNF-P-06', 'RQ-08');

it('etiqueta cada escenario con al menos un requisito', function (string $nombre): void {
    // Es el tercer formato del §9.6 y de el sale la fila de la matriz. Sin la
    // etiqueta, el escenario mide y no cuenta para nadie.
    $escenario = escenarioDeCarga(fuenteDeCarga('scan-peak.js'), $nombre);

    expect($escenario)->toMatch("/tags:\s*\{\s*requirements:\s*'[A-Z][A-Z0-9-]+/");
})->with(ESCENARIOS_DE_CARGA)->group('RQ-13');

it('apunta el exec de cada escenario a una funcion exportada del guion', function (string $nombre): void {
    // Un `exec` con un nombre que ya no existe hace que k6 se niegue a arrancar,
    // y el sintoma es «la pasada no dejo muestras» a los dos minutos de empezar.
    $fuente = fuenteDeCarga('scan-peak.js');
    $escenario = escenarioDeCarga($fuente, $nombre);

    $funcion = capturaDeCarga("/\bexec:\s*'([A-Za-z0-9_]+)'/", $escenario, 'El escenario no declara `exec`');

    expect($fuente)->toContain('export function '.$funcion.'(');
})->with(ESCENARIOS_DE_CARGA)->group('RQ-13');

it('asigna cada escenario a un rol y lanza los dos roles desde run.sh', function (): void {
    // Un escenario que no esta en ningun rol no lo ejecuta nadie; un rol que el
    // lanzador no arranca deja sin ejecutar todos los suyos.
    $fuente = fuenteDeCarga('scan-peak.js');
    $lanzador = fuenteDeCarga('run.sh');

    $reparto = capturaDeCarga(
        '/const SCENARIOS_BY_ROLE = \{(.+?)\n\}/s',
        $fuente,
        'scan-peak.js no declara SCENARIOS_BY_ROLE',
    );

    preg_match_all("/\b([a-z]+):\s*\[([^\]]*)\]/", $reparto, $roles, PREG_SET_ORDER);

    $asignados = [];

    foreach ($roles as $rol) {
        preg_match_all("/'([a-z]+)'/", $rol[2], $nombres);

        $asignados = array_merge($asignados, $nombres[1]);

        // `toBe` con mensaje y no `toContain` con mensaje: en una cadena, el
        // segundo argumento de `toContain` es OTRA aguja que buscar, no el texto
        // del fallo (trampa conocida de Pest).
        expect(preg_match('/k6_instance[^\n]*\b'.preg_quote($rol[1], '/').'\b/', $lanzador))
            ->toBe(1, 'run.sh no lanza ninguna instancia con el rol '.$rol[1]);
    }

    $esperados = ESCENARIOS_DE_CARGA;

    sort($asignados);
    sort($esperados);

    expect($asignados)->toBe($esperados);
})->group('RQ-13', 'RNF-P-06');

it('lanza k6 con el uid de quien invoca el script', function (): void {
    // `grafana/k6` corre como uid 12345. En un runner de Linux, `.results/` lo
    // crea el invocante con permisos 755 y el contenedor no puede escribir su
    // CSV: la pasada termina «sin muestras» sin que nada haya fallado a la
    // vista. Es el modo de fallo mas caro de diagnosticar de esta herramienta.
    $lanzador = fuenteDeCarga('run.sh');

    expect($lanzador)->toContain('id -u')
        ->and($lanzador)->toContain('--user');
})->group('RQ-13', 'RQ-08');
