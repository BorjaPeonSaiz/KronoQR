<?php

declare(strict_types=1);

use App\Support\Version\DeployedVersion;

/*
 * De donde sale la version que publica `GET /api/v1/health` (doc 02 §10.5).
 *
 * **Por que esto tiene pruebas propias y no basta con la del endpoint.** Lo que
 * se resuelve aqui es una cadena de precedencias que solo se equivoca en
 * produccion: en el portatil de quien la escribe, `APP_VERSION` no existe,
 * `IMAGE_TAG` vale `latest` y siempre gana el fichero. La prueba del endpoint
 * comprobaria una sola de las ramas —la del fichero— y las otras dos se
 * estrenarian el dia de la instalacion.
 *
 * **Y porque el contrato lo exige.** El esquema `Health` valida `version` contra
 * un patron SemVer: si esta resolucion devolviera `latest`, o una cadena vacia,
 * o el contenido de un fichero con un comentario dentro, el endpoint incumpliria
 * su propio contrato y el fallo aparecería en la instalacion del cliente, no
 * aqui.
 *
 * Suite unitaria: PHP puro, sin framework y sin base de datos. Los ficheros de
 * apoyo son temporales y se borran al terminar cada prueba.
 */

/** Directorio propio para no dejar restos entre las carpetas del sistema. */
function versionFilesDirectory(): string
{
    return sys_get_temp_dir().'/kronoqr-deployed-version';
}

/** Un fichero VERSION de mentira, con el contenido que pida la prueba. */
function versionFile(string $contents): string
{
    $path = versionFilesDirectory().'/'.bin2hex(random_bytes(8)).'.VERSION';

    file_put_contents($path, $contents);

    return $path;
}

beforeEach(function (): void {
    if (! is_dir(versionFilesDirectory())) {
        mkdir(versionFilesDirectory(), 0o777, true);
    }
});

afterEach(function (): void {
    foreach (glob(versionFilesDirectory().'/*') ?: [] as $file) {
        unlink($file);
    }
});

it('usa la version del entorno cuando ya tiene forma de SemVer', function (): void {
    // El caso de produccion: quien despliega ha dicho que version es, y eso manda
    // sobre cualquier fichero que la imagen lleve dentro.
    expect(DeployedVersion::resolve(['1.4.2'], [versionFile('9.9.9')]))->toBe('1.4.2');
});

it('acepta prelanzamientos y metadatos de construccion', function (string $version): void {
    // El patron del contrato los admite, y son justo las versiones que se
    // instalan en un piloto: rechazarlas obligaria a mentir en `/health`
    // precisamente cuando mas importa saber que hay desplegado.
    expect(DeployedVersion::resolve([$version], []))->toBe($version);
})->with(['1.4.2-rc.1', '1.4.2-beta', '1.4.2+build.7', '0.0.0-dev']);

it('cae al fichero VERSION cuando la etiqueta es latest', function (): void {
    // `IMAGE_TAG=latest` es el valor del .env.example y NO es SemVer: sin este
    // respaldo, cada instalacion recien montada incumpliria el contrato del
    // endpoint.
    expect(DeployedVersion::resolve(['latest'], [versionFile('1.0.0')]))->toBe('1.0.0');
});

it('cae al fichero cuando la variable no existe o esta vacia', function (mixed $declared): void {
    // `env()` devuelve `null` cuando la variable no esta declarada y una cadena
    // vacia cuando esta declarada sin valor. Los dos son «no se sabe».
    expect(DeployedVersion::resolve([$declared], [versionFile('1.0.0')]))->toBe('1.0.0');
})->with([[null], [''], ['   '], [false], [1]]);

it('cae al fichero ante cualquier forma que no sea SemVer', function (string $declared): void {
    // Una etiqueta de rama, una fecha, un `v` delante o un cuarto numero: todas
    // son etiquetas de imagen legitimas y ninguna vale como version del contrato.
    expect(DeployedVersion::resolve([$declared], [versionFile('1.0.0')]))->toBe('1.0.0');
})->with(['v1.2.3', '1.2', '1.2.3.4', 'main', '2026-08-27', 'sha-9f2c1ab', '1.0.0 y algo']);

it('respeta el orden en que se declaran las variables', function (): void {
    // Hoy son dos: `APP_VERSION`, que fija el build de la imagen y viaja dentro
    // del artefacto, y `IMAGE_TAG`, que fija el instalador. La primera manda.
    expect(DeployedVersion::resolve(['1.4.2', '9.9.9'], []))->toBe('1.4.2');
});

it('salta la variable invalida y usa la siguiente que si lo es', function (): void {
    // La imagen de desarrollo no fija `APP_VERSION`; la instalacion si fija
    // `IMAGE_TAG`. Que la primera falte no puede tapar a la segunda.
    expect(DeployedVersion::resolve([null, '1.4.2'], [versionFile('1.0.0')]))->toBe('1.4.2');
});

it('prueba las rutas del fichero en orden y usa la primera que existe', function (): void {
    // Las tres rutas de `config/app.php` son tres sitios donde puede correr el
    // proceso —imagen, arbol de fuentes y contenedor de desarrollo—, y solo una
    // existe en cada uno.
    $path = versionFile('1.0.0');

    expect(DeployedVersion::resolve([], [versionFilesDirectory().'/no-existe', $path]))->toBe('1.0.0');
});

it('lee solo la primera linea del fichero y sin espacios alrededor', function (string $contents): void {
    // Un salto de linea final, un retorno de carro de Windows o una linea de mas
    // no pueden convertir la version en algo que el contrato rechace.
    expect(DeployedVersion::resolve([], [versionFile($contents)]))->toBe('1.0.0');
})->with(["1.0.0\n", "1.0.0\r\n", "  1.0.0  \n", "1.0.0\n# la que viene despues", '1.0.0']);

it('ignora un fichero cuyo contenido no vale y sigue buscando', function (): void {
    expect(DeployedVersion::resolve([], [versionFile('en blanco'), versionFile('1.0.0')]))->toBe('1.0.0');
});

it('devuelve 0.0.0 cuando ni el entorno ni el repositorio saben decirlo', function (): void {
    // La sonda de vida NO PUEDE FALLAR: un error porque falta un fichero de
    // version es el reinicio en bucle que la sonda existe para evitar. `0.0.0`
    // cumple el contrato y se lee como lo que es.
    expect(DeployedVersion::resolve(['latest'], [versionFilesDirectory().'/no-existe']))
        ->toBe(DeployedVersion::UNKNOWN)
        ->toBe('0.0.0');
});

it('nunca devuelve algo que el contrato rechace', function (): void {
    // La garantia de arriba, dicha de una vez: pase lo que pase, lo que sale de
    // aqui casa con el patron del esquema `Health` de docs/api/openapi.yaml.
    $resolved = [
        DeployedVersion::resolve(['1.4.2'], []),
        DeployedVersion::resolve(['latest'], [versionFile('1.0.0')]),
        DeployedVersion::resolve([null], [versionFile('vacio')]),
        DeployedVersion::resolve([], []),
    ];

    foreach ($resolved as $version) {
        expect(preg_match(DeployedVersion::SEMVER, $version))->toBe(1, $version.' no es un SemVer.');
    }
});
