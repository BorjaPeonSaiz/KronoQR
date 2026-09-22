<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\Exception\AbsenceNotActive;
use App\Modules\Workforce\Domain\Exception\AbsenceRequiresNote;
use App\Modules\Workforce\Domain\Exception\InvalidAbsencePeriod;
use App\Modules\Workforce\Domain\Model\Absence;
use App\Modules\Workforce\Domain\ValueObject\AbsenceStatus;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;

/*
 * `Absence` — los limites del intervalo y el versionado (**RF-GP-04**, RN-13,
 * tarea 3.10).
 *
 * Suite unitaria: sin framework, sin base de datos y sin reloj. Lo que se
 * defiende aqui es la aritmetica de la que depende el informe de absentismo —un
 * dia de mas o de menos cambia el numero que alguien discute— y la regla dura 5:
 * corregir crea una version nueva y **conserva** la anterior.
 *
 * **Los resultados esperados se escriben como numero**, nunca se deducen con la
 * misma formula que el codigo: si se dedujeran, los dos podrian estar mal de la
 * misma manera.
 */

function ausencia(
    string $type = 'vacation',
    string $startsOn = '2026-03-02',
    string $endsOn = '2026-03-06',
    ?string $note = null,
    string $uuid = '0199f4a1-6c22-7e10-9b40-2a3b4c5d6e70',
): Absence {
    return new Absence(
        uuid: $uuid,
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        type: AbsenceType::from($type),
        startsOn: new DateTimeImmutable($startsOn.' 00:00:00', new DateTimeZone('UTC')),
        endsOn: new DateTimeImmutable($endsOn.' 00:00:00', new DateTimeZone('UTC')),
        note: $note,
    );
}

function dia(string $isoDate): DateTimeImmutable
{
    return new DateTimeImmutable($isoDate.' 00:00:00', new DateTimeZone('UTC'));
}

it('cubre el primer y el ultimo dia, y ni el anterior ni el posterior', function (): void {
    // **El limite exacto del que depende todo el informe.** Si `covers()` se
    // equivocara en un extremo, un dia de baja se contaria como absentismo no
    // justificado —o al reves— y el error seria invisible en el agregado.
    $baja = ausencia(type: 'sick_leave', startsOn: '2026-03-02', endsOn: '2026-03-06');

    expect($baja->covers(dia('2026-03-02')))->toBeTrue();
    expect($baja->covers(dia('2026-03-06')))->toBeTrue();
    expect($baja->covers(dia('2026-03-04')))->toBeTrue();

    expect($baja->covers(dia('2026-03-01')))->toBeFalse();
    expect($baja->covers(dia('2026-03-07')))->toBeFalse();
})->group('RF-GP-04');

it('cuenta los dos extremos al contar dias', function (string $desde, string $hasta, int $esperado): void {
    // Escrito como numero y no como `diff + 1`: es la misma razon por la que el
    // prorrateo del contrato no deduce sus minutos.
    expect(ausencia(startsOn: $desde, endsOn: $hasta)->days())->toBe($esperado);
})->with([
    'un solo dia' => ['2026-03-02', '2026-03-02', 1],
    'una semana laboral' => ['2026-03-02', '2026-03-06', 5],
    'un mes de marzo entero' => ['2026-03-01', '2026-03-31', 31],
    // Cruza el cambio de hora de primavera, que en Europa/Madrid es el 29 de
    // marzo de 2026: las fechas son calendario y el dia sigue siendo un dia.
    'cruzando el cambio de hora' => ['2026-03-28', '2026-03-30', 3],
])->group('RF-GP-04');

it('detecta el solape en los dos sentidos y en los extremos', function (string $desde, string $hasta, bool $solapa): void {
    // La comprobacion de verdad la hace PostgreSQL con `absences_no_overlap`;
    // esta existe para que la carga por fichero pueda decir en que linea esta el
    // choque antes de escribir nada.
    $registrada = ausencia(startsOn: '2026-03-10', endsOn: '2026-03-15');

    expect($registrada->overlaps(ausencia(startsOn: $desde, endsOn: $hasta)))->toBe($solapa);
})->with([
    'la anterior termina el dia antes' => ['2026-03-05', '2026-03-09', false],
    'la anterior termina el mismo dia que empieza' => ['2026-03-05', '2026-03-10', true],
    'contenida' => ['2026-03-11', '2026-03-12', true],
    'la envuelve' => ['2026-03-01', '2026-03-31', true],
    'empieza el mismo dia que la otra termina' => ['2026-03-15', '2026-03-20', true],
    'empieza el dia siguiente' => ['2026-03-16', '2026-03-20', false],
])->group('RF-GP-04');

it('rechaza un periodo invertido', function (): void {
    expect(static fn (): Absence => ausencia(startsOn: '2026-03-10', endsOn: '2026-03-01'))
        ->toThrow(InvalidAbsencePeriod::class);
})->group('RF-GP-04');

it('exige nota al tipo other y no a los demas', function (): void {
    // `other` existe para el permiso que no es ninguno de los tres; sin texto no
    // describe nada, y una categoria que no describe nada acaba usandose para
    // todo.
    expect(static fn (): Absence => ausencia(type: 'other'))->toThrow(AbsenceRequiresNote::class);

    // Una nota de espacios en blanco no es una nota.
    expect(static fn (): Absence => ausencia(type: 'other', note: '   '))->toThrow(AbsenceRequiresNote::class);

    expect(ausencia(type: 'other', note: 'Permiso por traslado.')->hasNote())->toBeTrue();
    expect(ausencia(type: 'vacation')->hasNote())->toBeFalse();
})->group('RF-GP-04');

it('corregir devuelve una version nueva y no toca la anterior', function (): void {
    // **Regla dura 5 y RN-13.** Lo que esta prueba defiende es que el objeto
    // anterior sigue diciendo exactamente lo mismo despues de corregirlo: si
    // `correctedWith()` mutara, la version historica dejaria de describir lo que
    // de verdad se registro.
    $original = ausencia(type: 'sick_leave', startsOn: '2026-03-02', endsOn: '2026-03-06');

    $corregida = $original->correctedWith(
        uuid: '0199f4a1-7d33-7f21-8a51-3b4c5d6e7f81',
        type: null,
        startsOn: null,
        endsOn: dia('2026-03-13'),
        note: null,
        noteGiven: false,
        reason: 'El parte de baja se prorrogo una semana.',
    );

    expect($corregida->uuid)->toBe('0199f4a1-7d33-7f21-8a51-3b4c5d6e7f81');
    expect($corregida->version)->toBe(2);
    expect($corregida->supersedesUuid)->toBe($original->uuid);
    expect($corregida->changeReason)->toBe('El parte de baja se prorrogo una semana.');
    expect($corregida->status)->toBe(AbsenceStatus::Active);
    // Lo omitido conserva su valor.
    expect($corregida->type)->toBe(AbsenceType::SickLeave);
    expect($corregida->isoStartsOn())->toBe('2026-03-02');
    expect($corregida->isoEndsOn())->toBe('2026-03-13');
    expect($corregida->days())->toBe(12);

    // Y LA ANTERIOR SIGUE INTACTA.
    expect($original->version)->toBe(1);
    expect($original->isoEndsOn())->toBe('2026-03-06');
    expect($original->changeReason)->toBeNull();
    expect($original->status)->toBe(AbsenceStatus::Active);
})->group('RF-GP-04', 'RN-13');

it('distingue borrar la nota de no tocarla', function (): void {
    // Es la unica forma de expresar en un `PATCH` «quita lo que hay ahi», que es
    // justo lo que hace falta cuando alguien escribio en la nota algo que no
    // debia.
    $con = ausencia(note: 'Aviso del hotel.');

    $sinTocar = $con->correctedWith(
        uuid: '0199f4a1-7d33-7f21-8a51-000000000001',
        type: null, startsOn: null, endsOn: null,
        note: null, noteGiven: false,
        reason: 'Se corrige la fecha, no la nota.',
    );

    $borrada = $con->correctedWith(
        uuid: '0199f4a1-7d33-7f21-8a51-000000000002',
        type: null, startsOn: null, endsOn: null,
        note: null, noteGiven: true,
        reason: 'La nota tenia informacion que no corresponde.',
    );

    expect($sinTocar->note)->toBe('Aviso del hotel.');
    expect($borrada->note)->toBeNull();
})->group('RF-GP-04', 'RN-13');

it('marcar como sustituida solo cambia el estado y el puntero', function (): void {
    // Regla dura 5: el unico cambio que este producto admite sobre una fila ya
    // escrita. Ni el tipo, ni las fechas, ni la nota.
    $original = ausencia(type: 'leave', note: 'Boda.');

    $sustituida = $original->supersededBy('0199f4a1-7d33-7f21-8a51-3b4c5d6e7f81');

    expect($sustituida->status)->toBe(AbsenceStatus::Superseded);
    expect($sustituida->supersededByUuid)->toBe('0199f4a1-7d33-7f21-8a51-3b4c5d6e7f81');
    expect($sustituida->type)->toBe(AbsenceType::Leave);
    expect($sustituida->note)->toBe('Boda.');
    expect($sustituida->isoStartsOn())->toBe($original->isoStartsOn());
    expect($sustituida->isActive())->toBeFalse();
})->group('RF-GP-04', 'RN-13');

it('anular no crea version y conserva el hecho', function (): void {
    // De un hecho que no paso no hay version posterior: `version` no sube y
    // `supersededByUuid` sigue vacio.
    $anulada = ausencia()->voidedWith(
        new DateTimeImmutable('2026-03-20T09:00:00', new DateTimeZone('UTC')),
        'Se registro a la persona equivocada.',
    );

    expect($anulada->status)->toBe(AbsenceStatus::Voided);
    expect($anulada->version)->toBe(1);
    expect($anulada->supersededByUuid)->toBeNull();
    expect($anulada->voidReason)->toBe('Se registro a la persona equivocada.');
    expect($anulada->voidedAt?->format('Y-m-d\TH:i:s'))->toBe('2026-03-20T09:00:00');
    // El hecho sigue ahi entero: lo unico que cambia es que sale del vigente.
    expect($anulada->isoStartsOn())->toBe('2026-03-02');
})->group('RF-GP-04');

it('no deja corregir ni anular una version que ya no es la vigente', function (): void {
    // ADR-035: el `uuid` identifica una VERSION. Dos personas mirando la misma
    // pantalla pueden corregir la misma fila a la vez; la segunda llega con un
    // identificador que ya paso a `superseded`.
    $sustituida = ausencia()->supersededBy('0199f4a1-7d33-7f21-8a51-3b4c5d6e7f81');

    expect(static fn (): Absence => $sustituida->correctedWith(
        uuid: '0199f4a1-7d33-7f21-8a51-000000000003',
        type: null, startsOn: null, endsOn: null,
        note: null, noteGiven: false,
        reason: 'Llego tarde.',
    ))->toThrow(AbsenceNotActive::class);

    $anulada = ausencia()->voidedWith(
        new DateTimeImmutable('2026-03-20T09:00:00', new DateTimeZone('UTC')),
        'Ya no procede.',
    );

    expect(static fn (): Absence => $anulada->voidedWith(
        new DateTimeImmutable('2026-03-21T09:00:00', new DateTimeZone('UTC')),
        'Otra vez.',
    ))->toThrow(AbsenceNotActive::class);
})->group('RF-GP-04', 'RN-13');

it('rechaza una ausencia que no toca ningun dia de la relacion laboral', function (): void {
    // Decision 2 de la ficha: esos dias no eran dias de trabajo, asi que
    // registrarlos no justifica nada y ensucia el informe. El solape PARCIAL si
    // se admite.
    $ausencia = ausencia(startsOn: '2026-03-02', endsOn: '2026-03-06');

    expect($ausencia->fallsWithinEmployment(dia('2026-04-01'), null))->toBeFalse();
    expect($ausencia->fallsWithinEmployment(dia('2026-01-01'), dia('2026-02-28')))->toBeFalse();

    // Parcial por el principio y por el final: entra.
    expect($ausencia->fallsWithinEmployment(dia('2026-03-04'), null))->toBeTrue();
    expect($ausencia->fallsWithinEmployment(dia('2026-01-01'), dia('2026-03-03')))->toBeTrue();
    // Y el dia exacto de alta y de cese cuentan.
    expect($ausencia->fallsWithinEmployment(dia('2026-03-06'), null))->toBeTrue();
    expect($ausencia->fallsWithinEmployment(dia('2026-01-01'), dia('2026-03-02')))->toBeTrue();
})->group('RF-GP-04');

it('reconoce el mismo hecho para que reimportar sea seguro', function (): void {
    // Una linea identica a una ausencia activa sale como `unchanged` y no como
    // solape: sin esta distincion, reimportar un cuadrante con una fila
    // corregida daria treinta y nueve conflictos donde no hay ninguno.
    $registrada = ausencia(type: 'vacation', startsOn: '2026-03-02', endsOn: '2026-03-06');

    expect($registrada->describesTheSameFactAs(ausencia(
        type: 'vacation', startsOn: '2026-03-02', endsOn: '2026-03-06', note: 'Otra nota',
    )))->toBeTrue();

    // La nota NO entra en la comparacion —quien reimporta no suele arrastrarla—
    // pero el tipo y las fechas si.
    expect($registrada->describesTheSameFactAs(ausencia(
        type: 'sick_leave', startsOn: '2026-03-02', endsOn: '2026-03-06',
    )))->toBeFalse();

    expect($registrada->describesTheSameFactAs(ausencia(
        type: 'vacation', startsOn: '2026-03-02', endsOn: '2026-03-07',
    )))->toBeFalse();
})->group('RF-GP-04');
