<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * PGOPTIONS de verdad, leido por libpq al abrir la conexion (tarea 3.6,
 * decision 14, RNF-P-06, RNF-D-01).
 *
 * QUE SE PRUEBA Y POR QUE INTEGRACION. `lock_timeout` e
 * `idle_in_transaction_session_timeout` son parametros de SESION de
 * PostgreSQL: solo se pueden comprobar contra un servidor real, preguntandole
 * con `SHOW`. Los inyectan compose.dev.yaml y compose.prod.yaml como
 * `PGOPTIONS="-c lock_timeout=... -c idle_in_transaction_session_timeout=..."`
 * en el ENTORNO DEL CONTENEDOR, y libpq (por tanto pdo_pgsql) lo lee del
 * entorno del propio proceso al abrir la conexion -- ninguna de las dos
 * conexiones de config/database.php declara un `options` de DSN que pudiera
 * pisarlo--. Aqui se simula ese arranque manipulando el entorno del proceso
 * de pruebas con `putenv()` y forzando una reconexion, que es lo mas cerca
 * que una prueba puede estar de "el contenedor arranco con este PGOPTIONS"
 * sin reiniciar nada.
 *
 * `disconnect()` y NO `purge()` -- la misma trampa que documenta
 * tests/Support/Concurrency/ParallelRequests.php--: aqui no hay `fork()`, pero
 * `purge()` saca el objeto `Connection` del `DatabaseManager` y cualquier
 * servicio que ya lo hubiera resuelto (el registro de auditoria es un
 * singleton) se queda con una referencia huerfana. `disconnect()` cierra el
 * socket y la siguiente consulta reconecta LIMPIO sobre el mismo objeto.
 *
 * POSTGRESQL NORMALIZA LA UNIDAD AL MOSTRARLA: 1234ms y 7777ms no son
 * multiplos exactos de una unidad mayor y `SHOW` los devuelve tal cual; 60s SI
 * lo es y vuelve como "1min". Verificado contra el PostgreSQL 17 real de este
 * repositorio antes de escribir estas aserciones (ver HANDOFF, tarea 3.6): no
 * es una suposicion sobre el formato de salida.
 */

afterEach(function (): void {
    // El entorno del proceso de pruebas es compartido entre ejemplos. Sin
    // esto, el siguiente test -o cualquier otra suite que comparta proceso
    // PHP- heredaria el PGOPTIONS de prueba en vez del que trae el contenedor
    // (vacio en la suite de pruebas: phpunit.xml no lo define).
    putenv('PGOPTIONS');
    DB::disconnect();
    DB::reconnect();
});

it('honra PGOPTIONS de lock_timeout tras reconectar', function (): void {
    putenv('PGOPTIONS=-c lock_timeout=1234ms');
    DB::disconnect();
    DB::reconnect();

    /** @var object{lock_timeout: string}|null $fila */
    $fila = DB::selectOne('SHOW lock_timeout');

    expect($fila?->lock_timeout)->toBe('1234ms');
})->group('RNF-P-06', 'RNF-D-01');

it('honra PGOPTIONS de idle_in_transaction_session_timeout tras reconectar', function (): void {
    putenv('PGOPTIONS=-c idle_in_transaction_session_timeout=7777ms');
    DB::disconnect();
    DB::reconnect();

    /** @var object{idle_in_transaction_session_timeout: string}|null $fila */
    $fila = DB::selectOne('SHOW idle_in_transaction_session_timeout');

    expect($fila?->idle_in_transaction_session_timeout)->toBe('7777ms');
})->group('RNF-P-06', 'RNF-D-01');

it('acepta los dos parametros juntos, con los valores de serie de .env.example', function (): void {
    // DB_LOCK_TIMEOUT=5s y DB_IDLE_IN_TRANSACTION_TIMEOUT=60s, tal y como los
    // compone compose.dev.yaml/compose.prod.yaml con PGOPTIONS.
    putenv('PGOPTIONS=-c lock_timeout=5s -c idle_in_transaction_session_timeout=60s');
    DB::disconnect();
    DB::reconnect();

    /** @var object{lock_timeout: string}|null $lockTimeout */
    $lockTimeout = DB::selectOne('SHOW lock_timeout');
    /** @var object{idle_in_transaction_session_timeout: string}|null $idleTimeout */
    $idleTimeout = DB::selectOne('SHOW idle_in_transaction_session_timeout');

    expect($lockTimeout?->lock_timeout)->toBe('5s');
    expect($idleTimeout?->idle_in_transaction_session_timeout)->toBe('1min');
})->group('RNF-P-06', 'RNF-D-01');
