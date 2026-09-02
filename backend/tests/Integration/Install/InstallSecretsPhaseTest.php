<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * La fase 3 del instalador —la que genera los secretos— ejecutada de verdad
 * (RF-PD-02, RS-08).
 *
 * ## El fallo que este fichero existe para que no vuelva
 *
 * La primera ejecucion de la etapa ⑧ salio con **5** («no se ha podido
 * deshacer»). La causa no estaba donde apuntaba el sintoma:
 *
 *   1. `random_password` era `tr -dc … </dev/urandom | head -c 32`. `head`
 *      cierra el descriptor al llegar a 32 bytes y `tr` —que lee un flujo
 *      infinito— muere por SIGPIPE. Con `pipefail`, la tuberia «falla».
 *   2. La fase 3 arma un `trap … ERR` y el script corre con `set -E`, asi que
 *      ese trap **se hereda dentro de la subshell** de `"$(random_password)"`.
 *   3. La vuelta atras se ejecutaba AHI: restauraba el `.env` —borrando las
 *      claves ya escritas—, consumia la copia previa y hacia `exit 4` de la
 *      subshell.
 *   4. El proceso padre no se entera: el estado de una sustitucion en posicion
 *      de argumento se descarta. Seguia escribiendo sobre un fichero ya
 *      restaurado, y el `.env` final **no tenia APP_KEY**.
 *   5. Tres fases despues, la vuelta atras legitima no encontraba la copia:
 *      salida 5.
 *
 * **El patron que hay que recordar: `set -E` + `trap ERR` + `pipefail` +
 * sustitucion de orden.** Cualquier tuberia que corte a su productor dentro de
 * un `$(...)` reproduce la clase entera del fallo.
 *
 * Se prueba SIN Docker, cargando `install.sh` con su guarda `BASH_SOURCE` y
 * llamando a `phase_secrets` sobre un `.env` temporal. Es exactamente la
 * reproduccion con la que se diagnostico.
 */

/** Las trece variables que la fase 3 deja escritas, con su longitud minima. */
const SECRETOS_ESPERADOS = [
    'APP_KEY' => 40,
    'QR_SIGNING_KEY_CURRENT' => 40,
    'QR_SIGNING_KEY_CURRENT_ID' => 2,
    'DB_PASSWORD' => 32,
    'DB_MIGRATION_PASSWORD' => 32,
    'BACKUP_DB_PASSWORD' => 32,
    'REVERB_APP_ID' => 16,
    'REVERB_APP_KEY' => 32,
    'REVERB_APP_SECRET' => 40,
    'BACKUP_ENCRYPTION_KEY' => 40,
    'IDENTITY_PIN_SEALING_SECRET_KEY' => 40,
    'GRAFANA_ADMIN_PASSWORD' => 32,
    'IMAGE_TAG' => 5,
];

/**
 * Corre un fragmento con install.sh cargado, sin instalar nada.
 */
function bashConLaFase3(string $script): Process
{
    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            'set -Eeuo pipefail; '
            .'. '.escapeshellarg(Repo::file('infra/scripts/install.sh')).' --check-only >/dev/null 2>&1 || true; '
            .$script
        ),
        timeout: 120.0,
    );
    $process->run();

    return $process;
}

function envTemporal(): string
{
    $fichero = sys_get_temp_dir().'/kronoqr-fase3-'.bin2hex(random_bytes(6)).'.env';
    copy(Repo::file('.env.example'), $fichero);

    return $fichero;
}

function limpiarEnvTemporal(string $fichero): void
{
    foreach ([$fichero, $fichero.'.kronoqr-pre-install'] as $ruta) {
        if (file_exists($ruta)) {
            unlink($ruta);
        }
    }
}

/**
 * Valor de una clave dentro de un .env ya escrito, o falla la prueba diciendo
 * cual falta. Una funcion y no `preg_match` suelto en cada sitio: asi el tipo
 * es `string` y no `array{}|array{…}`, y PHPStan no tiene que adivinar.
 */
function valorEscrito(string $contenido, string $clave): string
{
    if (preg_match('/^'.preg_quote($clave, '/').'=(.+)$/m', $contenido, $m) !== 1) {
        throw new RuntimeException($clave.' no se ha escrito en el .env.');
    }

    return trim($m[1]);
}

it('escribe los trece secretos y CONSERVA la copia previa del .env', function (): void {
    // La comprobacion que habria cazado el fallo: al terminar la fase 3, la
    // copia previa tiene que seguir ahi. Si una vuelta atras se ha colado en una
    // subshell, se la habra llevado por delante.
    $env = envTemporal();

    $resultado = bashConLaFase3(
        'ENV_FILE='.escapeshellarg($env).'; PRODUCT_VERSION=2.0.0; kq_msg_init es; phase_secrets'
    );

    expect($resultado->getExitCode())->toBe(0, $resultado->getErrorOutput())
        ->and(file_exists($env.'.kronoqr-pre-install'))
        ->toBeTrue('La fase 3 se ha comido la copia previa del .env: hay una vuelta atras ejecutandose donde no debe.');

    $contenido = (string) file_get_contents($env);

    foreach (SECRETOS_ESPERADOS as $clave => $minimo) {
        expect(mb_strlen(valorEscrito($contenido, $clave)))->toBeGreaterThanOrEqual(
            $minimo,
            $clave.' se ha escrito con menos de '.$minimo.' caracteres.'
        );
    }

    // Y lo intocable sigue intacto.
    expect($contenido)->toMatch('/^APP_TIMEZONE=UTC$/m');

    limpiarEnvTemporal($env);
})->group('RF-PD-02', 'RS-08');

it('no imprime ni uno de los secretos que acaba de generar', function (): void {
    // §3.5 y RS-08. Se comprueba contra los valores REALES recien escritos, que
    // es la unica forma honesta: buscar «algo que parezca una clave» daria verde
    // con cualquier fuga de verdad.
    $env = envTemporal();

    $resultado = bashConLaFase3(
        'ENV_FILE='.escapeshellarg($env).'; PRODUCT_VERSION=2.0.0; kq_msg_init es; phase_secrets'
    );

    $salida = $resultado->getOutput().$resultado->getErrorOutput();
    $contenido = (string) file_get_contents($env);

    foreach (array_keys(SECRETOS_ESPERADOS) as $clave) {
        // Ninguno de estos dos es un secreto, y los dos son cortos: coincidirian
        // por azar con cualquier salida. IMAGE_TAG es la version, que se imprime
        // a proposito; QR_SIGNING_KEY_CURRENT_ID son dos caracteres que viajan
        // EN CLARO dentro del propio payload del QR (`FH1.<key_id>.<token>.<sig>`,
        // doc 02 §5.1). Lo secreto es la clave, no su identificador.
        if (in_array($clave, ['IMAGE_TAG', 'QR_SIGNING_KEY_CURRENT_ID'], true)) {
            continue;
        }

        $valor = valorEscrito($contenido, $clave);

        expect(str_contains($salida, $valor))
            ->toBeFalse('El valor de '.$clave.' aparece en la salida de la fase 3.');
    }

    limpiarEnvTemporal($env);
})->group('RS-08');

it('deja el .env byte a byte como estaba cuando la vuelta atras se ejecuta desde main', function (): void {
    // La otra mitad: que la vuelta atras LEGITIMA si funciona, y que devuelve el
    // fichero del cliente exactamente como estaba —hash, no «parece igual»— con
    // el codigo 4 de la tabla comun.
    $env = envTemporal();
    $antes = (string) md5_file($env);

    $resultado = bashConLaFase3(
        'ENV_FILE='.escapeshellarg($env).'; PRODUCT_VERSION=2.0.0; COMPOSE_FILE=/no/importa; '
        .'kq_msg_init es; phase_secrets >/dev/null; '
        .'rollback_and_die "fallo simulado de migraciones"'
    );

    expect($resultado->getExitCode())->toBe(4)
        ->and(md5_file($env))->toBe($antes)
        ->and(file_exists($env.'.kronoqr-pre-install'))->toBeFalse()
        ->and($resultado->getErrorOutput())->toContain('Vuelta atras completada');

    limpiarEnvTemporal($env);
})->group('RF-PD-02');

it('no deshace nada cuando el trap ERR salta dentro de una subshell', function (): void {
    // La guarda `BASHPID != $$` de rollback_and_die, probada por su efecto: tras
    // un fallo dentro de una sustitucion de orden, el .env sigue teniendo sus
    // secretos y la copia previa sigue ahi. Sin la guarda, las dos cosas
    // desaparecen y el sintoma no llega hasta tres fases despues.
    $env = envTemporal();

    $resultado = bashConLaFase3(
        'ENV_FILE='.escapeshellarg($env).'; PRODUCT_VERSION=2.0.0; COMPOSE_FILE=/no/importa; '
        .'kq_msg_init es; phase_secrets >/dev/null 2>&1; '
        .'trap \'rollback_and_die "no deberia deshacer nada"\' ERR; '
        // Una tuberia que falla dentro de una sustitucion, igual que la que
        // rompio la etapa ⑧.
        .'resultado="$( (exit 3) | cat; printf ok)" || true; '
        .'trap - ERR; printf "%s\n" "${resultado:-vacio}"'
    );

    $contenido = (string) file_get_contents($env);

    expect(file_exists($env.'.kronoqr-pre-install'))
        ->toBeTrue('Una vuelta atras se ha ejecutado dentro de una subshell y se ha llevado la copia previa.')
        ->and($contenido)->toMatch('/^APP_KEY=base64:.+$/m')
        ->and($resultado->getOutput())->toContain('ok');

    limpiarEnvTemporal($env);
})->group('RF-PD-02');

it('genera contrasenas de 32 caracteres sin disparar el trap, veinte veces seguidas', function (): void {
    // El generador que fallaba, ejercitado bajo las MISMAS condiciones que en la
    // fase 3: `set -Eeuo pipefail` con un trap ERR de centinela armado. Si
    // vuelve a aparecer una tuberia que mata a su productor, el centinela
    // escribe y esta prueba cae.
    $resultado = bashConLaFase3(
        'trap \'echo CENTINELA_DISPARADO >&2\' ERR; '
        .'for i in $(seq 1 20); do '
        .'clave="$(random_password)"; '
        .'printf "%s\n" "${#clave}"; '
        .'done'
    );

    expect($resultado->getExitCode())->toBe(0)
        ->and($resultado->getErrorOutput())->not->toContain('CENTINELA_DISPARADO');

    $longitudes = array_values(array_filter(explode("\n", trim($resultado->getOutput()))));

    expect($longitudes)->toHaveCount(20);

    foreach ($longitudes as $longitud) {
        expect((int) $longitud)->toBe(32);
    }
})->group('RF-PD-02');

it('no deja en los scripts ninguna tuberia que lea un flujo infinito', function (): void {
    // La clase entera del fallo, no solo su instancia. Bajo `set -E` + trap ERR
    // + pipefail, cualquier tuberia cuyo consumidor pare antes que el productor
    // dispara la vuelta atras dentro de la subshell que la contenga.
    foreach ([
        'infra/scripts/install.sh',
        'infra/scripts/backup.sh',
        'infra/scripts/restore.sh',
        'infra/scripts/restore-drill.sh',
        'infra/scripts/lib/backup-common.sh',
    ] as $script) {
        $contenido = (string) file_get_contents(Repo::file($script));

        // Las lineas de comentario no cuentan: la explicacion del fallo cita el
        // patron a proposito.
        $codigo = (string) preg_replace('/^\s*#.*$/m', '', $contenido);

        expect(str_contains($codigo, '/dev/urandom |'))
            ->toBeFalse($script.' vuelve a leer /dev/urandom a traves de una tuberia.');

        expect(str_contains($codigo, '</dev/urandom | head'))
            ->toBeFalse($script.' vuelve a cortar un flujo infinito con head.');
    }
})->group('RF-PD-02');
