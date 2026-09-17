<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * El pool de PHP-FPM y las dos duraciones de PGOPTIONS que RENDERIZA y VALIDA
 * `entrypoint.sh` para el rol `fpm` (tarea 3.6, decision 13/14, RNF-P-06,
 * RNF-D-01): `PHP_FPM_MAX_CHILDREN` deriva `pm.start_servers` (20 %),
 * `pm.min_spare_servers` (10 %) y `pm.max_spare_servers` (30 %), con un piso
 * de 1 en cada uno, el orden que PHP-FPM exige (min_spare <= start <=
 * max_spare <= max_children) y un techo de cordura de 500; `DB_LOCK_TIMEOUT`
 * y `DB_IDLE_IN_TRANSACTION_TIMEOUT` tienen que parecer una duracion de
 * PostgreSQL o PGOPTIONS rechaza la conexion entera.
 *
 * DOS NIVELES DE PRUEBA, PORQUE EL BINARIO NO SIEMPRE ESTA. La etapa ④ de la
 * CI instala PHP directamente en el runner (`.github/actions/php-toolchain`)
 * para correr Integration: NO hay `php-fpm` ni
 * `/usr/local/etc/php-fpm.d/www.conf`, que solo existen DENTRO de la imagen
 * `kronoqr/app`. Con el binario, las pruebas de mas abajo EJECUTAN las
 * funciones de verdad -incluida la propia llamada de `render_fpm_pool` a
 * `php-fpm -t`- y comprueban el resultado. Sin el, la ultima prueba del
 * fichero afirma sobre el TEXTO fuente de `entrypoint.sh` que las formulas y
 * los patrones siguen ahi: no ejecuta nada, pero corre siempre -en la CI y en
 * local- y detecta que alguien las reescriba sin mover las pruebas
 * funcionales con ellas. `.github/workflows/load-test.yml` añade un tercer
 * nivel: un par de `docker run` sueltos contra `kronoqr/app:ci`, justo
 * despues de construir las imagenes, que SI ejecutan el camino real en la CI
 * (measurement barato, minutos antes de instalar nada).
 *
 * COMO SE EJECUTA SIN DISPARAR `main()`. Las funciones viven en
 * `infra/docker/php/entrypoint.sh`, que TERMINA con el mismo guardia que
 * `infra/scripts/install.sh` (`if [ "${BASH_SOURCE[0]}" = "$0" ]; then main
 * "$@"; fi`). Sourceado con `.` dentro de un `bash -c` la condicion es falsa
 * -BASH_SOURCE[0] es la ruta del fichero, $0 es "bash"-, asi que `main()` no
 * corre y la unica llamada es la explicita.
 *
 * EL FALLO QUE LAS PRUEBAS FUNCIONALES DE render_fpm_pool() EXISTEN PARA QUE
 * NO VUELVA. La primera version dejaba que `php-fpm -t` escribiera su
 * "NOTICE: ... test is successful" en el MISMO stdout que `render_fpm_pool`
 * usa para devolver la ruta del fichero rendido: `rendered_pool="$
 * (render_fpm_pool)"` capturaba las dos lineas juntas, y `exec php-fpm -y
 * "${rendered_pool}"` intentaba abrir una ruta multilinea que no existia --
 * arranque real, probado en caliente antes de esta prueba (ver HANDOFF, tarea
 * 3.6). `php-fpm -t` se captura ahora en una variable local y solo se
 * imprime -por `log`, a stderr- si falla.
 */

/** Por que estas pruebas no corren en todos los entornos: nunca un identificador de requisito aqui dentro (el escaner de trazabilidad lo tomaria como otra etiqueta). */
const SIN_PHP_FPM_DE_VERDAD = 'Esta prueba necesita el binario php-fpm y el pool fuente, que solo viven dentro de la imagen del producto.';

/**
 * El binario `php-fpm` y el fichero fuente del pool solo existen dentro de la
 * imagen del producto. Mismo patron que `hayChromium()` en
 * PeriodReportPdfSealTest.php: se comprueba lo que de verdad hace falta, no
 * un nombre de entorno.
 */
function hayFpmDeVerdad(): bool
{
    return is_file('/usr/local/bin/kronoqr-entrypoint')
        && is_file('/usr/local/etc/php-fpm.d/www.conf')
        && trim((string) shell_exec('command -v php-fpm 2>/dev/null')) !== '';
}

/**
 * Ejecuta `render_fpm_pool()` con `PHP_FPM_MAX_CHILDREN` a un valor dado (o
 * sin definir, si es `null`), y devuelve el proceso terminado. Solo tiene
 * sentido llamarla cuando `hayFpmDeVerdad()` es cierto.
 */
function renderizarPoolFpm(?string $maxChildren): Process
{
    $exportacion = $maxChildren === null
        ? 'unset PHP_FPM_MAX_CHILDREN;'
        : 'export PHP_FPM_MAX_CHILDREN='.escapeshellarg($maxChildren).';';

    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg($exportacion.' . /usr/local/bin/kronoqr-entrypoint; render_fpm_pool'),
        timeout: 30.0,
    );
    $process->run();

    return $process;
}

/**
 * Ejecuta `validate_pgoptions_durations()` con `DB_LOCK_TIMEOUT` y
 * `DB_IDLE_IN_TRANSACTION_TIMEOUT` a los valores dados (o sin definir, si son
 * `null`), y devuelve el proceso terminado. Solo tiene sentido llamarla
 * cuando `hayFpmDeVerdad()` es cierto.
 */
function validarDuracionesPgoptions(?string $lockTimeout, ?string $idleTimeout): Process
{
    $exportacion = $lockTimeout === null
        ? 'unset DB_LOCK_TIMEOUT;'
        : 'export DB_LOCK_TIMEOUT='.escapeshellarg($lockTimeout).';';
    $exportacion .= $idleTimeout === null
        ? 'unset DB_IDLE_IN_TRANSACTION_TIMEOUT;'
        : 'export DB_IDLE_IN_TRANSACTION_TIMEOUT='.escapeshellarg($idleTimeout).';';

    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg($exportacion.' . /usr/local/bin/kronoqr-entrypoint; validate_pgoptions_durations'),
        timeout: 30.0,
    );
    $process->run();

    return $process;
}

it('rinde un pool valido con el valor de serie (PHP_FPM_MAX_CHILDREN sin definir, equivale a 20)', function (): void {
    $proceso = renderizarPoolFpm(null);

    expect($proceso->isSuccessful())->toBeTrue($proceso->getErrorOutput());

    $rendido = trim($proceso->getOutput());
    expect(is_file($rendido))->toBeTrue("No existe el fichero rendido: {$rendido}");

    $contenido = (string) file_get_contents($rendido);
    expect($contenido)->toContain('pm.max_children = 20')
        ->toContain('pm.start_servers = 4')
        ->toContain('pm.min_spare_servers = 2')
        ->toContain('pm.max_spare_servers = 6')
        // El [global] que reemplaza al php-fpm.conf por defecto: sin el, el
        // arranque real busca su error_log en una ruta que `app` no puede
        // crear (ver el docblock de render_fpm_pool en entrypoint.sh).
        ->toContain('error_log = /proc/self/fd/2');
})->group('RNF-P-06')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('con PHP_FPM_MAX_CHILDREN=1 los cuatro valores colapsan a 1, todos validos', function (): void {
    $proceso = renderizarPoolFpm('1');

    expect($proceso->isSuccessful())->toBeTrue($proceso->getErrorOutput());

    $contenido = (string) file_get_contents(trim($proceso->getOutput()));
    expect($contenido)->toContain('pm.max_children = 1')
        ->toContain('pm.start_servers = 1')
        ->toContain('pm.min_spare_servers = 1')
        ->toContain('pm.max_spare_servers = 1');
})->group('RNF-P-06')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('con PHP_FPM_MAX_CHILDREN=40 (el recomendado) deriva 8/4/12', function (): void {
    $proceso = renderizarPoolFpm('40');

    expect($proceso->isSuccessful())->toBeTrue($proceso->getErrorOutput());

    $contenido = (string) file_get_contents(trim($proceso->getOutput()));
    expect($contenido)->toContain('pm.max_children = 40')
        ->toContain('pm.start_servers = 8')
        ->toContain('pm.min_spare_servers = 4')
        ->toContain('pm.max_spare_servers = 12');
})->group('RNF-P-06')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('con PHP_FPM_MAX_CHILDREN=500 (el techo de cordura) sigue siendo valido', function (): void {
    $proceso = renderizarPoolFpm('500');

    expect($proceso->isSuccessful())->toBeTrue($proceso->getErrorOutput());

    $contenido = (string) file_get_contents(trim($proceso->getOutput()));
    expect($contenido)->toContain('pm.max_children = 500');
})->group('RNF-P-06')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('rechaza PHP_FPM_MAX_CHILDREN=501, por encima del techo de cordura, con salida 2 y que hacer', function (): void {
    // A ~60 MB por trabajador, 501 son ~30 GB: casi siempre un cero de mas en
    // el .env, no un servidor de verdad (ninguno del §11.6.2 lo pide).
    $proceso = renderizarPoolFpm('501');

    expect($proceso->getExitCode())->toBe(2);
    expect($proceso->getErrorOutput())
        ->toContain('PHP_FPM_MAX_CHILDREN')
        ->toContain('500')
        ->toContain('Que hacer');
})->group('RNF-P-06')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('rechaza un PHP_FPM_MAX_CHILDREN que no es un entero, con salida 2 y que hacer', function (): void {
    $proceso = renderizarPoolFpm('abc');

    expect($proceso->getExitCode())->toBe(2);
    expect($proceso->getErrorOutput())
        ->toContain('PHP_FPM_MAX_CHILDREN')
        ->toContain('Que hacer');
})->group('RNF-P-06')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('rechaza PHP_FPM_MAX_CHILDREN=0, que no es >= 1', function (): void {
    $proceso = renderizarPoolFpm('0');

    expect($proceso->getExitCode())->toBe(2);
})->group('RNF-P-06')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('rechaza un PHP_FPM_MAX_CHILDREN negativo', function (): void {
    $proceso = renderizarPoolFpm('-5');

    expect($proceso->getExitCode())->toBe(2);
})->group('RNF-P-06')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('el fichero rendido pasa su propia validacion con php-fpm -t', function (): void {
    $proceso = renderizarPoolFpm('20');
    expect($proceso->isSuccessful())->toBeTrue();

    $validacion = new Process(['php-fpm', '-t', '-y', trim($proceso->getOutput())], timeout: 10.0);
    $validacion->run();

    expect($validacion->isSuccessful())->toBeTrue($validacion->getErrorOutput());
    expect($validacion->getErrorOutput())->toContain('test is successful');
})->group('RNF-P-06')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('DB_LOCK_TIMEOUT y DB_IDLE_IN_TRANSACTION_TIMEOUT sin definir valen (5s/60s por omision)', function (): void {
    $proceso = validarDuracionesPgoptions(null, null);

    expect($proceso->isSuccessful())->toBeTrue($proceso->getErrorOutput());
})->group('RNF-P-06', 'RNF-D-01')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('acepta duraciones validas de PostgreSQL: con unidad y sin unidad', function (string $duracion): void {
    // Sin unidad ("5000") PostgreSQL asume milisegundos: sintaxis valida de
    // GUC, no un descuido de quien escribe el .env.
    $proceso = validarDuracionesPgoptions($duracion, $duracion);

    expect($proceso->isSuccessful())->toBeTrue($proceso->getErrorOutput());
})->with(['5s', '5000', '60min', '1h', '500ms'])->group('RNF-P-06', 'RNF-D-01')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('DB_LOCK_TIMEOUT vacio (no ausente: la variable existe y no tiene valor) cae al de serie, no se rechaza', function (): void {
    // `${DB_LOCK_TIMEOUT:-5s}` en bash usa el valor por omision tanto si la
    // variable no existe como si existe vacia: un .env con la linea
    // "DB_LOCK_TIMEOUT=" (sin valor) tiene que seguir arrancando con 5s, no
    // romperse. Verificado en caliente antes de escribir esta prueba.
    $proceso = validarDuracionesPgoptions('', '60s');

    expect($proceso->isSuccessful())->toBeTrue($proceso->getErrorOutput());
})->group('RNF-P-06', 'RNF-D-01')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('rechaza una duracion con espacio o sin forma reconocible, con salida 2 y que hacer', function (string $duracion) {
    // "5 s" (con espacio) rompe la sintaxis de PGOPTIONS -un `-c` con un
    // token suelto detras- y PostgreSQL rechazaria la conexion ENTERA con un
    // motivo que no se parece a "revisa el .env": exactamente lo que esta
    // validacion evita.
    $proceso = validarDuracionesPgoptions($duracion, '60s');

    expect($proceso->getExitCode())->toBe(2);
    expect($proceso->getErrorOutput())
        ->toContain('DB_LOCK_TIMEOUT')
        ->toContain('Que hacer');
})->with(['5 s', 'abc'])->group('RNF-P-06', 'RNF-D-01')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('rechaza DB_IDLE_IN_TRANSACTION_TIMEOUT invalido aunque DB_LOCK_TIMEOUT sea correcto', function (): void {
    $proceso = validarDuracionesPgoptions('5s', 'abc');

    expect($proceso->getExitCode())->toBe(2);
    expect($proceso->getErrorOutput())
        ->toContain('DB_IDLE_IN_TRANSACTION_TIMEOUT')
        ->toContain('Que hacer');
})->group('RNF-P-06', 'RNF-D-01')->skip(! hayFpmDeVerdad(), SIN_PHP_FPM_DE_VERDAD);

it('la formula de derivacion y las validaciones siguen en el texto de entrypoint.sh (corre siempre, sin Docker)', function (): void {
    $fuente = Repo::contents('infra/docker/php/entrypoint.sh');

    expect($fuente)
        ->toContain('render_fpm_pool')
        ->toContain('validate_pgoptions_durations')
        ->toContain('PHP_FPM_MAX_CHILDREN:-20')
        ->toContain('max * 20 / 100')
        ->toContain('max * 10 / 100')
        ->toContain('max * 30 / 100')
        ->toContain('-gt 500')
        ->toContain('s/^pm\.max_children = .*/pm.max_children = ${max}/')
        ->toContain('s/^pm\.start_servers = .*/pm.start_servers = ${start}/')
        ->toContain('s/^pm\.min_spare_servers = .*/pm.min_spare_servers = ${min_spare}/')
        ->toContain('s/^pm\.max_spare_servers = .*/pm.max_spare_servers = ${max_spare}/')
        ->toContain('php-fpm -t -y')
        ->toContain('exec php-fpm --nodaemonize -y "${rendered_pool}"')
        ->toContain('DB_LOCK_TIMEOUT:-5s')
        ->toContain('DB_IDLE_IN_TRANSACTION_TIMEOUT:-60s')
        ->toContain('(ms|s|min|h)')
        ->toContain('Que hacer');
})->group('RNF-P-06', 'RNF-D-01');
