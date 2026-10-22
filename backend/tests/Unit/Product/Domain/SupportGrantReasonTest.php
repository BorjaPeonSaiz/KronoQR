<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidSupportGrant;
use App\Modules\Product\Domain\Model\SupportGrant;
use App\Modules\Product\Domain\ValueObject\SupportGrantAuthor;
use App\Modules\Product\Domain\ValueObject\SupportScope;

/*
 * El motivo de una concesion de soporte no lleva datos personales y tiene
 * plazo (tarea 11.3 C; RGPD art. 5.1.c y 5.1.e, RL-11, ADR-020).
 */

function motivoSoporteConcedido(string $reason): SupportGrant
{
    return SupportGrant::grant(
        uuid: '0199f6a2-0000-7d3b-8a90-1b2c3d4e5f60',
        grantedBy: new SupportGrantAuthor(id: 7, uuid: '0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f60', name: 'Cuenta de prueba'),
        reason: $reason,
        scope: SupportScope::Diagnostics,
        hours: 24,
        maximumHours: 72,
        grantedAt: new DateTimeImmutable('2026-06-15T09:00:00Z'),
    );
}

it('reconoce un correo, un DNI o NIE y un telefono', function (string $reason, string $kind): void {
    expect(SupportGrant::personalDataIn($reason))->toBe($kind);
})->with([
    'correo' => ['Revisar la ficha de lucia.ferrer@hotel.example', 'email'],
    'DNI pegado' => ['El de 12345678Z no ficha', 'national_id'],
    'DNI con guion' => ['El de 12345678-Z no ficha', 'national_id'],
    'NIE' => ['La persona X1234567L no aparece', 'national_id'],
    'NIE con espacios' => ['La persona Y 1234567 L no aparece', 'national_id'],
    'DNI con puntos de millar' => ['DNI 12.345.678-Z', 'national_id'],
    'movil' => ['Llamar al 612345678', 'phone'],
    'fijo' => ['Llamar al 912 345 678', 'phone'],
    'movil con espacios' => ['Llamar al 612 345 678', 'phone'],
    'con prefijo' => ['Llamar al +34 612-34-56-78', 'phone'],
])->group('RL-15', 'RF-PD-11');

it('no confunde un uuid, un numero de incidencia ni una fecha con un dato personal', function (string $reason): void {
    expect(SupportGrant::personalDataIn($reason))->toBeNull();
})->with([
    'incidencia corta' => ['Incidencia #123: la cola del quiosco de recepcion no vacia'],
    'uuid de empleado' => ['Revisar 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'],
    'uuid con un grupo de solo cifras' => ['Revisar 01990000-1234-7123-8123-123456789012'],
    'fecha' => ['Jornada del 2026-10-21 sin cerrar'],
    'arroba suelta' => ['Fallo @ recepcion'],
    'ocho cifras sin letra' => ['Lote 12345678 de la importacion'],
    'fecha con hora' => ['Fallo desde 2026-10-22 10:30'],
    'fecha ISO completa' => ['Fallo desde 2026-10-22T10:30:00Z en recepcion'],
    'direccion IPv4' => ['El quiosco 192.168.1.50 no sincroniza'],
    'incidencia de nueve cifras' => ['Incidencia #123456789'],
    'dos tickets de ocho cifras' => ['Ticket 12345678 y el 87654321'],
    'dos fechas compactas' => ['Jornadas de 20261022 a 20261031'],
    'letra de control en minuscula suelta' => ['Lote 12345678 z'],
])->group('RL-15', 'RF-PD-11');

it('rechaza conceder con un dato personal en el motivo, y dice cual sin repetirlo', function (): void {
    $rejection = null;

    try {
        motivoSoporteConcedido('Revisar a lucia.ferrer@hotel.example');
    } catch (InvalidSupportGrant $caught) {
        $rejection = $caught;
    }

    expect($rejection)->toBeInstanceOf(InvalidSupportGrant::class);

    if ($rejection instanceof InvalidSupportGrant) {
        expect($rejection->translationKey)->toBe('support.errors.reason_personal_data')
            ->and($rejection->parameters)->toBe([])
            ->and($rejection->getMessage())->toBe('A support grant reason must not carry personal data, and this one looks like it carries an email address.')
            ->and($rejection->getMessage())->not->toContain('lucia');
    }
})->group('RL-15', 'RF-PD-11');

it('nombra la clase de dato en el mensaje tecnico', function (string $kind, string $mensaje): void {
    $exception = match ($kind) {
        'email' => InvalidSupportGrant::personalData('email'),
        'phone' => InvalidSupportGrant::personalData('phone'),
        default => InvalidSupportGrant::personalData('national_id'),
    };

    expect($exception->getMessage())->toBe($mensaje)
        ->and($exception->translationKey)->toBe('support.errors.reason_personal_data')
        ->and($exception->parameters)->toBe([]);
})->with([
    ['email', 'A support grant reason must not carry personal data, and this one looks like it carries an email address.'],
    ['national_id', 'A support grant reason must not carry personal data, and this one looks like it carries a national ID.'],
    ['phone', 'A support grant reason must not carry personal data, and this one looks like it carries a phone number.'],
])->group('RF-PD-11');

it('no se construye con motivo y fecha de vaciado a la vez, ni sin ninguno de los dos', function (?string $reason, ?DateTimeImmutable $purgedAt): void {
    expect(static fn (): SupportGrant => SupportGrant::fromStorage(
        id: 3,
        uuid: '0199f6a2-0000-7d3b-8a90-1b2c3d4e5f60',
        grantedBy: new SupportGrantAuthor(id: 7, uuid: '0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f60', name: 'Cuenta de prueba'),
        reason: $reason,
        scope: SupportScope::Diagnostics,
        grantedAt: new DateTimeImmutable('2026-06-15T09:00:00Z'),
        expiresAt: new DateTimeImmutable('2026-06-16T09:00:00Z'),
        revokedAt: null,
        revokedByUserId: null,
        accessedAt: null,
        reasonPurgedAt: $purgedAt,
    ))->toThrow(
        InvalidArgumentException::class,
        'Una concesion de soporte no tiene motivo exactamente cuando se vacio: motivo y fecha de vaciado van juntos (tarea 11.3 C).',
    );
})->with([
    'motivo y fecha' => ['Incidencia #123', new DateTimeImmutable('2026-10-01T03:45:00Z')],
    'ni motivo ni fecha' => [null, null],
])->group('RL-11', 'RF-PD-11');

it('nace con el motivo y sin fecha de vaciado', function (): void {
    $grant = motivoSoporteConcedido('  Incidencia #123  ');

    expect($grant->reason)->toBe('Incidencia #123')
        ->and($grant->reasonPurgedAt)->toBeNull()
        ->and($grant->withId(4)->reasonPurgedAt)->toBeNull();
})->group('RL-11', 'RF-PD-11');

it('se reconstruye sin motivo y con su fecha de vaciado, y la conserva al ganar id', function (): void {
    $purgedAt = new DateTimeImmutable('2026-10-01T03:45:00Z');

    $grant = SupportGrant::fromStorage(
        id: 3,
        uuid: '0199f6a2-0000-7d3b-8a90-1b2c3d4e5f60',
        grantedBy: new SupportGrantAuthor(id: 7, uuid: '0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f60', name: 'Cuenta de prueba'),
        reason: null,
        scope: SupportScope::Diagnostics,
        grantedAt: new DateTimeImmutable('2026-06-15T09:00:00Z'),
        expiresAt: new DateTimeImmutable('2026-06-16T09:00:00Z'),
        revokedAt: null,
        revokedByUserId: null,
        accessedAt: null,
        reasonPurgedAt: $purgedAt,
    );

    expect($grant->reason)->toBeNull()
        ->and($grant->reasonPurgedAt)->toBe($purgedAt)
        ->and($grant->withId(5)->reasonPurgedAt)->toBe($purgedAt)
        ->and($grant->withId(5)->reason)->toBeNull();
})->group('RL-11', 'RF-PD-11');
