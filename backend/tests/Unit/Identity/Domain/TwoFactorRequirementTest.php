<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Policy\TwoFactorRequirement;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/*
 * **RS-06**: «2FA obligatorio para `admin`, `rrhh` y `auditor`».
 *
 * La lista entra por el constructor porque es configuracion y no una constante
 * (regla dura 13): un cliente con una politica mas dura la amplia sin tocar el
 * repositorio. Lo que estas pruebas fijan es la **regla**, no la lista.
 */

/**
 * La politica con los tres roles de serie de RS-06.
 */
function politicaDeSegundoFactor(): TwoFactorRequirement
{
    return new TwoFactorRequirement([UserRole::ADMIN, UserRole::RRHH, UserRole::AUDITOR]);
}

it('obliga a los tres roles con alcance sobre toda la plantilla', function (UserRole $role): void {
    expect(politicaDeSegundoFactor()->isMandatoryFor([$role]))->toBeTrue();
})->with([
    'admin' => UserRole::ADMIN,
    'rrhh' => UserRole::RRHH,
    'auditor' => UserRole::AUDITOR,
])->group('RS-06');

it('no obliga al responsable de departamento, cuyo alcance esta acotado', function (): void {
    // RF-ID-03 es lo que hace que no haga falta: no alcanza a toda la plantilla.
    expect(politicaDeSegundoFactor()->isMandatoryFor([UserRole::RESPONSABLE_DEPARTAMENTO]))->toBeFalse();
})->group('RS-06', 'RF-ID-03');

it('obliga a una cuenta que suma un rol obligado a otro que no lo es', function (): void {
    // Basta un rol obligado: quien es responsable **y** RRHH alcanza la plantilla
    // entera por la segunda via.
    expect(politicaDeSegundoFactor()->isMandatoryFor([UserRole::RESPONSABLE_DEPARTAMENTO, UserRole::RRHH]))
        ->toBeTrue();
})->group('RS-06');

it('reta a quien ya activo su segundo factor aunque su rol no lo exija', function (): void {
    // Lo contrario convertiria una proteccion que alguien eligio tener en un
    // adorno, y dejaria su cuenta a la altura de su contrasena sin avisarle.
    $politica = politicaDeSegundoFactor();

    expect($politica->challenges([UserRole::RESPONSABLE_DEPARTAMENTO], secondFactorActive: true))->toBeTrue()
        ->and($politica->challenges([UserRole::RESPONSABLE_DEPARTAMENTO], secondFactorActive: false))->toBeFalse();
})->group('RS-06');

it('exige dar de alta el segundo factor solo a quien esta obligado y no lo tiene', function (): void {
    $politica = politicaDeSegundoFactor();

    expect($politica->enrolmentRequired([UserRole::RRHH], secondFactorActive: false))->toBeTrue()
        // Ya lo tiene: hay reto, pero no alta.
        ->and($politica->enrolmentRequired([UserRole::RRHH], secondFactorActive: true))->toBeFalse()
        // No esta obligado y no lo tiene: ni reto ni alta.
        ->and($politica->enrolmentRequired([UserRole::RESPONSABLE_DEPARTAMENTO], secondFactorActive: false))->toBeFalse();
})->group('RS-06');

it('no obliga a nadie con la lista vacia, y sigue retando a quien lo activo', function (): void {
    // El caso de una instalacion que decide no exigirlo. Desactivar la
    // obligatoriedad no puede desactivar el segundo factor de quien ya lo tiene:
    // seria quitarle una proteccion sin decirselo.
    $sinObligados = new TwoFactorRequirement([]);

    expect($sinObligados->isMandatoryFor([UserRole::ADMIN]))->toBeFalse()
        ->and($sinObligados->challenges([UserRole::ADMIN], secondFactorActive: false))->toBeFalse()
        ->and($sinObligados->challenges([UserRole::ADMIN], secondFactorActive: true))->toBeTrue();
})->group('RS-06');
