<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Support\PresenceChannels;
use App\Modules\Reporting\Domain\ValueObject\PresenceEntry;
use App\Modules\Reporting\Domain\ValueObject\PresenceStatus;
use App\Modules\Shared\Domain\ValueObject\AccessScope;

/*
 * A que canal puede entrar cada cual, y a que canales sale cada cambio
 * (RF-ID-03, ADR-011, regla dura 18).
 *
 * Prueba **unitaria** porque es una regla de autorizacion pura: dos conjuntos y
 * una pertenencia. Su version de extremo a extremo —que
 * `POST /api/v1/broadcasting/auth` responde `403`— vive en
 * `Tests\Feature\Reporting\PresenceChannelAuthorizationTest`, y hacen falta las
 * dos: esta dice que la regla es correcta y aquella que esta enchufada.
 */

it('da el canal global a quien no tiene restriccion de alcance', function (): void {
    $canales = PresenceChannels::forScope(AccessScope::unrestricted());

    expect($canales)->toBe(['presence.all'])
        ->and(PresenceChannels::mayJoinAll(AccessScope::unrestricted()))->toBeTrue();
})->group('RF-ID-03', 'RF-PA-01');

it('da al responsable un canal por departamento y nunca el global', function (): void {
    // Suscribirlo al global le daria en tiempo real justo lo que RF-ID-03 le
    // niega en el listado, y se lo seguiria dando cuando se cree un departamento
    // nuevo que no dirige.
    $alcance = AccessScope::forDepartments(3, 7);

    expect(PresenceChannels::forScope($alcance))
        ->toBe(['presence.department.3', 'presence.department.7'])
        ->and(PresenceChannels::mayJoinAll($alcance))->toBeFalse();
})->group('RF-ID-03', 'RS-04');

it('no da ningun canal a un responsable sin departamentos', function (): void {
    // Una lista vacia significa «nadie», no «sin restriccion»: es el fallo que
    // RF-ID-03 existe para impedir.
    $alcance = AccessScope::forDepartments();

    expect(PresenceChannels::forScope($alcance))->toBe([])
        ->and(PresenceChannels::mayJoinAll($alcance))->toBeFalse()
        ->and(PresenceChannels::mayJoinDepartment($alcance, 3))->toBeFalse();
})->group('RF-ID-03', 'RS-04');

it('deja entrar a un responsable solo en los departamentos que dirige', function (): void {
    $alcance = AccessScope::forDepartments(3);

    expect(PresenceChannels::mayJoinDepartment($alcance, 3))->toBeTrue()
        ->and(PresenceChannels::mayJoinDepartment($alcance, 4))->toBeFalse();
})->group('RF-ID-03', 'RS-04');

it('difunde el cambio al canal del departamento y al global, y a ninguno mas', function (): void {
    $entrada = new PresenceEntry(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        fullName: 'Youssef Amrani',
        departmentId: 3,
        departmentName: 'Cocina',
        status: PresenceStatus::Present,
        shiftEntryUuid: '0199f2c1-8a10-7b40-9c50-6d7e8f9a0b11',
        clockedInAt: new DateTimeImmutable('2026-03-14T05:00:00Z'),
        origin: 'qr_kiosk',
        deviceUuid: null,
        deviceName: null,
    );

    expect(PresenceChannels::forEntry($entrada))
        ->toBe(['presence.all', 'presence.department.3']);
})->group('RF-PA-01', 'RF-ID-03');

it('difunde solo al canal global el cambio de quien no tiene departamento', function (): void {
    // Coherente con `AccessScope::reaches()`: nadie dirige el departamento de
    // quien no tiene ninguno, y atribuirselo a un responsable cualquiera seria
    // inventar una jerarquia.
    $entrada = new PresenceEntry(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        fullName: 'Sin Departamento',
        departmentId: null,
        departmentName: null,
        status: PresenceStatus::Absent,
        shiftEntryUuid: null,
        clockedInAt: null,
        origin: null,
        deviceUuid: null,
        deviceName: null,
    );

    expect(PresenceChannels::forEntry($entrada))->toBe(['presence.all']);
})->group('RF-PA-01', 'RF-ID-03');
