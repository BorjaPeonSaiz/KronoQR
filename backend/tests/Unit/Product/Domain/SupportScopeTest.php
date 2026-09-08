<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/*
 * El catalogo de alcances de soporte (RF-PD-11, RL-19, ADR-020, doc 02 §7.3).
 *
 * ## Por que este fichero existe, y no solo el de la entidad
 *
 * Porque `SupportScope::abilities()` es **la cuarta copia** de las cadenas de
 * ambito del producto. El docblock de `TokenAbility` ya declara que sus valores
 * viven en tres sitios —el enum, el contrato OpenAPI y la migracion del catalogo
 * de roles— porque «OpenAPI no puede referenciar codigo» y «el esquema de una
 * instalacion no puede depender de una clase de la aplicacion», y remata: *«las
 * tres copias las ata una prueba, no la buena fe»*.
 *
 * `Product` no puede importar nada de `Identity` (doc 02 §1.6, verificado por
 * Deptrac), asi que la cuarta copia era inevitable. Esta es la prueba que la
 * ata.
 *
 * **Lo que hace peligrosa a una errata aqui es que no rompe nada visible.** Un
 * `attendance:reed` no falla al emitir el token: simplemente ese token no
 * autoriza y soporte llama diciendo «no puedo ver nada». Y un ambito de mas
 * tampoco falla: autoriza de mas, en silencio, hasta que alguien lo mira.
 */

it('solo concede ambitos que existen en el catalogo de la instalacion', function (SupportScope $scope): void {
    expect($scope->abilities())->not->toBe([]);

    foreach ($scope->abilities() as $ability) {
        // `toContain` recibe valores variadicos, no un mensaje: el detalle va
        // en la afirmacion previa, que si lo admite.
        expect(\in_array($ability, TokenAbility::names(), true))->toBeTrue(
            'El alcance «'.$scope->value.'» concede «'.$ability.'», que no existe en TokenAbility.',
        );
    }
})->with(SupportScope::cases())->group('RF-PD-11', 'RS-04');

it('ningun alcance concede las potestades que son solo del cliente', function (SupportScope $scope): void {
    /*
     * La lista literal de «lo que ningun alcance concede nunca» de la tabla de
     * `POST /api/v1/support/grants`: activar licencias, conceder o revocar
     * accesos de soporte, emitir o revocar credenciales, corregir fichajes,
     * gestionar la plantilla y generar informes o la exportacion para la
     * Inspeccion.
     *
     * Son actos del CLIENTE. El fabricante no los hace ni con permiso, y por eso
     * no basta con que hoy no esten: tiene que fallar el dia en que alguien los
     * añada «para poder ayudar mejor».
     */
    $prohibidos = [
        TokenAbility::LICENSE_ALL,
        TokenAbility::SUPPORT_ALL,
        TokenAbility::EMPLOYEES_ALL,
        TokenAbility::CREDENTIALS_ALL,
        TokenAbility::ATTENDANCE_CORRECT,
        TokenAbility::REPORTS_ALL,
        TokenAbility::REPORTS_LEGAL,
    ];

    foreach ($prohibidos as $prohibido) {
        expect(\in_array($prohibido->value, $scope->abilities(), true))->toBeFalse(
            'El alcance «'.$scope->value.'» concede «'.$prohibido->value.'», que es una potestad del cliente.',
        );
    }
})->with(SupportScope::cases())->group('RF-PD-11', 'RL-19');

it('el alcance de serie es el mas estrecho y no alcanza ningun dato personal', function (): void {
    // Quien concede deprisa, en mitad de una incidencia, acaba con el minimo y
    // no con el maximo.
    expect(SupportScope::default())->toBe(SupportScope::Diagnostics)
        ->and(SupportScope::Diagnostics->abilities())->toBe(['diagnostics:*']);
})->group('RF-PD-11', 'RL-19');

it('cada alcance añade a lo anterior y no sustituye', function (): void {
    // La tabla del contrato dice «lo anterior y...» en las dos filas de abajo.
    // Si un alcance dejara de llevar `diagnostics:*`, la concesion mas amplia no
    // podria generar el paquete, que es lo primero que hace soporte.
    foreach (SupportScope::cases() as $scope) {
        expect($scope->abilities())->toContain(TokenAbility::DIAGNOSTICS_ALL->value);
    }
})->group('RF-PD-11');

it('read_only es exactamente las tres familias de LECTURA del §7.3', function (): void {
    expect(SupportScope::ReadOnly->abilities())->toBe([
        TokenAbility::DIAGNOSTICS_ALL->value,
        TokenAbility::ATTENDANCE_READ->value,
        TokenAbility::EMPLOYEES_READ->value,
        TokenAbility::AUDIT_READ->value,
    ]);
})->group('RF-PD-11', 'RL-19');

it('configuration no lleva ningun ambito de lectura de datos personales', function (): void {
    // Cambiar un ajuste y leer el registro de la plantilla son dos potestades
    // distintas, y quien necesita la primera no necesita la segunda.
    expect(SupportScope::Configuration->abilities())->toBe([
        TokenAbility::DIAGNOSTICS_ALL->value,
        TokenAbility::SETTINGS_ALL->value,
    ]);
})->group('RF-PD-11', 'RL-19');

it('los tres alcances actuan como admin ante las policies', function (SupportScope $scope): void {
    // Lo que separa a uno de otro son los ambitos, no el rol. El porque esta en
    // el docblock de `SupportScope::actsAs()`; que los ambitos de lectura no
    // abran ninguna ruta de escritura lo comprueba `SupportScopeRoutesTest`.
    expect($scope->actsAs())->toBe(UserRole::ADMIN);
})->with(SupportScope::cases())->group('RF-PD-11');

it('el catalogo del enum es el que declara el contrato', function (): void {
    // El esquema `SupportScope` del contrato enumera estos tres y ni uno mas.
    expect(SupportScope::names())->toBe(['diagnostics', 'read_only', 'configuration']);
})->group('RF-PD-11', 'RQ-06');
