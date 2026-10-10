<?php

declare(strict_types=1);

use App\Modules\Kiosk\Domain\Policy\AppVersionPolicy;
use App\Modules\Kiosk\Domain\ValueObject\AppVersion;
use App\Modules\Kiosk\Domain\ValueObject\AppVersionStanding;

/*
 * Cuando esta desfasada la aplicacion de un quiosco (RF-KI-07, RF-PA-07;
 * 2.2.1, bloque 1, tarea 1.2).
 *
 * La regla entera vive en `AppVersionPolicy` y su docblock la explica; aqui se
 * fija caso a caso. Lo que mas importa son los sufijos: sin tratarlos, un
 * entorno de pruebas veria todas sus tablets desfasadas y el aviso se
 * aprenderia a ignorar.
 */

it('juzga la version del quiosco contra la del servidor', function (
    string $deployed,
    ?string $declared,
    AppVersionStanding $standing,
): void {
    $policy = AppVersionPolicy::forDeployed($deployed);

    expect($policy->standingOf($declared))->toBe($standing)
        ->and($policy->isBehind($declared))->toBe($standing === AppVersionStanding::Behind);
})->with([
    // El nucleo, en sus tres posiciones y en los dos sentidos.
    'igual' => ['2.2.1', '2.2.1', AppVersionStanding::Current],
    'parche anterior' => ['2.2.1', '2.2.0', AppVersionStanding::Behind],
    'menor anterior' => ['2.2.1', '2.1.9', AppVersionStanding::Behind],
    'mayor anterior' => ['2.2.1', '1.9.9', AppVersionStanding::Behind],
    'parche posterior (vuelta atras, ADR-054)' => ['2.2.1', '2.2.2', AppVersionStanding::Ahead],
    'menor posterior' => ['2.2.1', '2.3.0', AppVersionStanding::Ahead],
    'mayor posterior' => ['2.2.1', '3.0.0', AppVersionStanding::Ahead],
    // Numerico y no lexicografico: 2.10.0 va despues de 2.9.0.
    'dos cifras, anterior' => ['2.10.0', '2.9.0', AppVersionStanding::Behind],
    'dos cifras, posterior' => ['2.9.0', '2.10.0', AppVersionStanding::Ahead],

    // Lo que no se puede leer cuenta como desfasado: necesita el build nuevo.
    'la 0.0.0 de la 2.1.0' => ['2.2.1', '0.0.0', AppVersionStanding::Behind],
    '0.0.0-dev de un build sin VERSION' => ['2.2.1', '0.0.0-dev', AppVersionStanding::Behind],
    'null' => ['2.2.1', null, AppVersionStanding::Behind],
    'vacia' => ['2.2.1', '', AppVersionStanding::Behind],
    'no SemVer: dos componentes' => ['2.2.1', '2.2', AppVersionStanding::Behind],
    'no SemVer: con v delante' => ['2.2.1', 'v2.2.1', AppVersionStanding::Behind],
    'no SemVer: latest' => ['2.2.1', 'latest', AppVersionStanding::Behind],
    'no SemVer: cero a la izquierda' => ['2.2.1', '2.02.1', AppVersionStanding::Behind],
    'no SemVer: espacio' => ['2.2.1', ' 2.2.1', AppVersionStanding::Behind],
    'no SemVer: salto de linea al final' => ['2.2.1', "2.2.1\n", AppVersionStanding::Behind],
    'no SemVer: prerelease vacio' => ['2.2.1', '2.2.1-', AppVersionStanding::Behind],
    'no SemVer: identificador vacio' => ['2.2.1', '2.2.1-rc..1', AppVersionStanding::Behind],
    'no SemVer: build vacio' => ['2.2.1', '2.2.1+', AppVersionStanding::Behind],
    'no SemVer: componente de diez cifras' => ['2.2.1', '2.2.1000000000', AppVersionStanding::Behind],

    // Sufijos del quiosco: solo cuenta el nucleo.
    'quiosco -dev con el mismo nucleo' => ['2.2.1', '2.2.1-dev', AppVersionStanding::Current],
    'quiosco -rc con el mismo nucleo' => ['2.2.1', '2.2.1-rc.1', AppVersionStanding::Current],
    'quiosco con build' => ['2.2.1', '2.2.1+sha.abc123', AppVersionStanding::Current],
    'quiosco con prerelease y build' => ['2.2.1', '2.2.1-ci.4+sha.abc', AppVersionStanding::Current],
    'quiosco -dev de un nucleo anterior' => ['2.2.1', '2.2.0-dev', AppVersionStanding::Behind],
    'quiosco con build de un nucleo anterior' => ['2.2.1', '2.2.0+sha.abc', AppVersionStanding::Behind],

    // Sufijos del servidor que SI se comparan: es la CI probando la actualizacion.
    // El primero parte de que la CI construye la PWA con el mismo `APP_VERSION`
    // que el servidor (`2.2.2-ci` los dos), que es como se construye.
    'servidor -ci, quiosco del mismo nucleo' => ['2.2.2-ci', '2.2.2-ci', AppVersionStanding::Current],
    'servidor -ci, quiosco publicado del mismo nucleo' => ['2.2.2-ci', '2.2.2', AppVersionStanding::Current],
    'servidor -ci, quiosco anterior' => ['2.2.2-ci', '2.2.1', AppVersionStanding::Behind],
    'servidor -rc, quiosco anterior' => ['2.3.0-rc.1', '2.2.9', AppVersionStanding::Behind],
    'servidor con build' => ['2.2.1+sha.abc', '2.2.0', AppVersionStanding::Behind],
    'servidor -devel no es desarrollo' => ['2.2.1-devel', '2.2.0', AppVersionStanding::Behind],

    // Servidor sin version comparable: no se juzga a nadie.
    'servidor -dev' => ['2.2.1-dev', '0.0.0', AppVersionStanding::Unchecked],
    'servidor -dev.3' => ['2.2.1-dev.3', '1.0.0', AppVersionStanding::Unchecked],
    'servidor 0.0.0-dev del Dockerfile' => ['0.0.0-dev', '2.2.1', AppVersionStanding::Unchecked],
    'servidor 0.0.0' => ['0.0.0', null, AppVersionStanding::Unchecked],
    'servidor latest' => ['latest', '2.2.1', AppVersionStanding::Unchecked],
    'servidor vacio' => ['', '2.2.1', AppVersionStanding::Unchecked],
])->group('RF-KI-07', 'RF-PA-07');

it('anuncia como version minima el nucleo del servidor, sin sufijos', function (?string $deployed, ?string $minimum): void {
    $policy = AppVersionPolicy::forDeployed($deployed);

    expect($policy->minimumAppVersion())->toBe($minimum)
        ->and($policy->isEnforced())->toBe($minimum !== null);
})->with([
    'publicada' => ['2.2.1', '2.2.1'],
    'de la CI' => ['2.2.2-ci', '2.2.2'],
    'con build' => ['2.2.1+sha.abc', '2.2.1'],
    'con dos cifras' => ['10.20.30', '10.20.30'],
    // Sin version que exigir el campo se omite del latido (regla dura 19).
    'desarrollo' => ['2.2.1-dev', null],
    'desconocida' => ['0.0.0', null],
    'no SemVer' => ['latest', null],
    'null' => [null, null],
])->group('RF-KI-07', 'RF-PA-07');

it('desmonta una version SemVer en sus partes', function (): void {
    $version = AppVersion::tryParse('12.3.45-dev.2+sha.abc');

    expect($version)->not->toBeNull()
        ->and($version?->major)->toBe(12)
        ->and($version?->minor)->toBe(3)
        ->and($version?->patch)->toBe(45)
        ->and($version?->prerelease)->toBe('dev.2')
        ->and($version?->core())->toBe('12.3.45')
        ->and($version?->isDevelopmentBuild())->toBeTrue()
        ->and($version?->isUnknown())->toBeFalse()
        ->and(AppVersion::tryParse('1.0.0')?->prerelease)->toBeNull()
        ->and(AppVersion::tryParse('1.0.0+b')?->prerelease)->toBeNull()
        ->and(AppVersion::tryParse('1.0.0')?->isDevelopmentBuild())->toBeFalse()
        ->and(AppVersion::tryParse('1.0.0-ci')?->isDevelopmentBuild())->toBeFalse()
        ->and(AppVersion::tryParse('0.0.1')?->isUnknown())->toBeFalse()
        ->and(AppVersion::tryParse('0.1.0')?->isUnknown())->toBeFalse()
        ->and(AppVersion::tryParse('1.0.0')?->isUnknown())->toBeFalse()
        ->and(AppVersion::tryParse('0.0.0+b')?->isUnknown())->toBeTrue();
})->group('RF-KI-07');
