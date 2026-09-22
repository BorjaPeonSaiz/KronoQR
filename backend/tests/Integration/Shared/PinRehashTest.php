<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Migracion del coste de `bcrypt` del PIN al acertarlo** (hallazgo **H-13** de
 * la revision interna ASVS de 2026-09).
 *
 * La otra mitad de `Tests\Integration\Identity\PasswordRehashTest`, y la que mas
 * veces al dia se ejecuta en una instalacion: el PIN es la credencial del portal
 * del empleado (RL-05, ADR-015) y la del fichaje de respaldo del quiosco
 * (RF-AT-11). Sin `needsRehash`, subir el coste el dia que 12 se quede corto
 * exigiria reemitir el PIN de toda la plantilla **y volver a entregarlo en
 * mano**, que es dias de trabajo de RRHH.
 *
 * Se ejercita el puerto {@see EmployeePinVerifier} y no el endpoint: lo que se
 * afirma es que la fila queda reescrita, y el verificador es una sola
 * implementacion para las dos puertas.
 */

uses(RefreshDatabase::class);

/**
 * El hash guardado del PIN, leido por la tabla.
 *
 * `pin_hash` esta fuera de `$fillable` y en `$hidden` del modelo justamente para
 * que no salga por un `toArray()`, asi que se lee como lo lee el producto: con
 * el constructor de consultas.
 */
function hashDePinDe(string $employeeUuid): string
{
    $valor = DB::table('employees')->where('uuid', $employeeUuid)->value('pin_hash');

    return \is_string($valor) ? $valor : '';
}

/**
 * Deja el PIN guardado con un coste inferior al vigente, como lo tendria una
 * instalacion que lleva años funcionando.
 */
function conPinAlCosteAntiguo(string $employeeUuid, string $pin): string
{
    $antiguo = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 10]);

    DB::table('employees')->where('uuid', $employeeUuid)->update([
        'pin_hash' => $antiguo,
        'pin_issued_at' => now(),
    ]);

    return $antiguo;
}

it('rehashea al coste vigente el PIN guardado con un coste inferior', function (): void {
    $site = WorkforceFixtures::site();
    $empleado = WorkforceFixtures::employee($site);

    $antiguo = conPinAlCosteAntiguo($empleado, '482913');
    $codigo = EmployeePins::codeOf($empleado);

    expect($antiguo)->toStartWith('$2y$10$');

    $resultado = app(EmployeePinVerifier::class)->verify($codigo, '482913', PinOrigin::PORTAL);

    // Acertar sigue siendo acertar: migrar no puede dejar a nadie fuera de su
    // portal.
    expect($resultado->isVerified())->toBeTrue();

    $migrado = hashDePinDe($empleado);

    expect($migrado)->not->toBe($antiguo)
        ->and(Hash::needsRehash($migrado))->toBeFalse()
        ->and(Hash::check('482913', $migrado))->toBeTrue();

    // Y el PIN sigue valiendo contra el hash nuevo en el intento siguiente, que
    // es la mitad que de verdad importa.
    expect(app(EmployeePinVerifier::class)->verify($codigo, '482913', PinOrigin::PORTAL)->isVerified())
        ->toBeTrue();
})->group('RS-12');

it('no toca el hash del PIN que ya esta al coste vigente', function (): void {
    // El rehash es una migracion, no un efecto de cada acceso al portal.
    $site = WorkforceFixtures::site();
    $empleado = WorkforceFixtures::employee($site);

    EmployeePins::issue($empleado, '703641');

    $antes = hashDePinDe($empleado);

    app(EmployeePinVerifier::class)->verify(EmployeePins::codeOf($empleado), '703641', PinOrigin::PORTAL);

    expect(hashDePinDe($empleado))->toBe($antes);
})->group('RS-12');

it('no rehashea nada cuando el PIN no es el bueno', function (): void {
    /*
     * **RS-03 y regla dura 17.** Los cinco rechazos del verificador hacen el
     * mismo trabajo y en el mismo orden, y el rehash se queda fuera de todos
     * ellos: un `bcrypt` de mas en una sola de las ramas seria una diferencia
     * medible con un cronometro desde la tablet de la entrada. Aqui se afirma por
     * su efecto observable, que es que la fila no se toca.
     */
    $site = WorkforceFixtures::site();
    $empleado = WorkforceFixtures::employee($site);

    $antiguo = conPinAlCosteAntiguo($empleado, '482913');

    $resultado = app(EmployeePinVerifier::class)
        ->verify(EmployeePins::codeOf($empleado), '000000', PinOrigin::KIOSK);

    expect($resultado->isVerified())->toBeFalse()
        ->and(hashDePinDe($empleado))->toBe($antiguo);
})->group('RS-12', 'RS-03');
