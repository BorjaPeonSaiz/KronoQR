<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ErrorColumnSanitizer;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;

/*
 * Las columnas de `error_events` que no son el mensaje (ADR-048 decision 5, H7;
 * RF-PD-15, RL-19): `app_version`, `file`, `exception_class` y `code`.
 */

it('filtra app_version por la lista blanca y conserva una version', function (string $entrada, string $esperado): void {
    expect(ErrorColumnSanitizer::appVersion($entrada))->toBe($esperado);
})->with([
    'version' => ['2.2.0', '2.2.0'],
    'candidata' => ['2.2.0-rc.1', '2.2.0-rc.1'],
    'un nombre' => ['RosaFicticiana', '…'],
    'un nombre con version' => ['2.2.0-Ficticiana', '2.2.0-…'],
    'una fecha de compilacion' => ['2.2.0+20261003', '2.2.0+[n]'],
])->group('RF-PD-15', 'RL-19');

it('recorta app_version a su columna sin dejar media palabra', function (): void {
    $version = ErrorColumnSanitizer::appVersion(str_repeat('ok.', 20));

    expect(mb_strlen($version))->toBeLessThanOrEqual(ErrorColumnSanitizer::MAX_APP_VERSION)
        ->and(ErrorColumnSanitizer::appVersion($version))->toBe($version);
})->group('RF-PD-15');

it('conserva una ruta del producto y filtra cualquier otra', function (?string $entrada, ?string $esperado): void {
    expect(ErrorColumnSanitizer::file($entrada))->toBe($esperado);
})->with([
    'del modulo' => ['app/Modules/Product/Domain/ValueObject/ErrorEvent.php', 'app/Modules/Product/Domain/ValueObject/ErrorEvent.php'],
    'de vendor' => ['vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'vendor/laravel/framework/src/Illuminate/Database/Connection.php'],
    'de configuracion' => ['config/logging.php', 'config/logging.php'],
    'absoluta con un nombre' => ['/home/ficticiana/Inventadez.php', '/home/…/….php'],
    'del producto pero no php' => ['app/Ficticiana.txt', 'app/….txt'],
    'nula' => [null, null],
])->group('RF-PD-15', 'RL-19');

it('conserva un nombre de clase cualificado y filtra cualquier otra cosa', function (?string $entrada, ?string $esperado): void {
    expect(ErrorColumnSanitizer::exceptionClass($entrada))->toBe($esperado);
})->with([
    'cualificada' => ['App\\Modules\\Workforce\\Domain\\Exception\\EmployeeCodeAlreadyTaken', 'App\\Modules\\Workforce\\Domain\\Exception\\EmployeeCodeAlreadyTaken'],
    'global' => ['RuntimeException', 'RuntimeException'],
    // El byte nulo que PHP pone en el nombre se quita: PostgreSQL no lo admite.
    'anonima con ruta' => ["class@anonymous\0/home/ficticiana/x.php:3", 'class@anonymous/home/…/x.php:3'],
    'nula' => [null, null],
])->group('RF-PD-15', 'RL-19');

it('del servidor solo conserva SQLSTATE, un entero corto o el desbordamiento', function (?string $entrada, ?string $esperado): void {
    expect(ErrorColumnSanitizer::code(ErrorSource::Api, $entrada))->toBe($esperado);
})->with([
    'sqlstate' => ['23505', '23505'],
    'sqlstate con letras' => ['23P01', '23P01'],
    'entero' => ['42', '42'],
    'entero de cuatro' => ['1234', '1234'],
    // Cinco cifras es tambien un SQLSTATE (23505): no se puede distinguir.
    'cinco cifras, forma de SQLSTATE' => ['12345', '12345'],
    'entero de seis' => ['739104', null],
    'texto' => ['Ficticiana', null],
    'sqlstate en minusculas' => ['23p01', null],
    'cinco letras sin cifra' => ['ABCDE', null],
    'desbordamiento' => ['overflow', 'overflow'],
    'nulo' => [null, null],
])->group('RF-PD-15', 'RL-19');

it('de un cliente conserva el codigo del catalogo y filtra cualquier otro', function (?string $entrada, ?string $esperado): void {
    expect(ErrorColumnSanitizer::code(ErrorSource::Kiosk, $entrada))->toBe($esperado);
})->with([
    'del catalogo' => ['kiosk.camera.unavailable', 'kiosk.camera.unavailable'],
    'desbordamiento' => ['overflow', 'overflow'],
    'fuera del catalogo' => ['kiosk.Ficticiana', 'kiosk.…'],
    'nulo' => [null, null],
])->group('RF-PD-15', 'RL-19');

it('es idempotente en las cuatro columnas', function (): void {
    $version = ErrorColumnSanitizer::appVersion('2.2.0-Ficticiana');
    $fichero = (string) ErrorColumnSanitizer::file('/home/ficticiana/Inventadez.php');
    $clase = (string) ErrorColumnSanitizer::exceptionClass('class@anonymous/x');
    $codigo = (string) ErrorColumnSanitizer::code(ErrorSource::Admin, 'web.Ficticiana');

    expect(ErrorColumnSanitizer::appVersion($version))->toBe($version)
        ->and(ErrorColumnSanitizer::file($fichero))->toBe($fichero)
        ->and(ErrorColumnSanitizer::exceptionClass($clase))->toBe($clase)
        ->and(ErrorColumnSanitizer::code(ErrorSource::Admin, $codigo))->toBe($codigo);
})->group('RF-PD-15');

it('recorta a 255 una ruta del producto o una clase demasiado largas', function (): void {
    $ruta = 'app/'.str_repeat('a/', 130).'X.php';
    $clase = 'App\\'.str_repeat('A\\', 130).'X';

    expect(mb_strlen((string) ErrorColumnSanitizer::file($ruta)))->toBe(ErrorColumnSanitizer::MAX_CLASS)
        ->and(mb_strlen((string) ErrorColumnSanitizer::exceptionClass($clase)))->toBe(ErrorColumnSanitizer::MAX_CLASS);
})->group('RF-PD-15');

it('quita los espacios de los extremos de app_version', function (): void {
    expect(ErrorColumnSanitizer::appVersion(' 2.2.0 '))->toBe('2.2.0');
})->group('RF-PD-15');

it('recorta app_version por el principio y sin espacios al final del corte', function (): void {
    expect(ErrorColumnSanitizer::appVersion(str_repeat('ok.', 20)))->toBe(str_repeat('ok.', 10).'ok')
        ->and(ErrorColumnSanitizer::appVersion(str_repeat('ok ', 10).'x yy ok'))
        // Los espacios se colapsan y el corte (en el espacio tras la «x») se recorta.
        ->toBe(str_repeat('ok ', 10).'x');
})->group('RF-PD-15');
