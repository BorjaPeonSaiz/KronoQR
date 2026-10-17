<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\Exception\InvalidSiteTimezoneCorrection;
use App\Modules\Workforce\Domain\Exception\SiteTimezoneLocked;
use App\Modules\Workforce\Domain\Exception\WorkforceConflict;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezoneCorrection;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezoneLock;

/*
 * **LA ZONA HORARIA DEL CENTRO QUEDA FIJA CON EL PRIMER TRAMO** (RN-23,
 * ADR-056).
 *
 * La zona decide a que jornada va cada tramo (RN-05) y con ella se presenta y
 * se exporta todo el historico. El centro recibe ya resuelto si hay registro
 * horario —como recibe un umbral (regla dura 14)— y decide: con registro, otra
 * zona se niega; la misma zona nunca. La correccion por consola es la unica
 * salida, y no mira el bloqueo.
 */

function siteLockMadrid(): Site
{
    return Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->withId(1);
}

/** La firma de una correccion de consola: motivo, tramos que ya existian y cuenta que responde. */
function siteLockFirma(): SiteTimezoneCorrection
{
    return SiteTimezoneCorrection::of('Se puso Madrid y el hotel esta en Tenerife', 3, 7);
}

it('cambia la zona mientras no hay registro horario', function (): void {
    $site = siteLockMadrid()->relocateTo(SiteTimezone::fromString('Atlantic/Canary'), SiteTimezoneLock::Open);

    expect($site->timezone->identifier)->toBe('Atlantic/Canary')
        ->and($site->name)->toBe('Hotel Marina')
        ->and($site->id)->toBe(1);
})->group('RN-23', 'RN-05');

it('niega otra zona en cuanto hay registro horario, con un conflicto que dice las dos', function (): void {
    $intento = static fn (): Site => siteLockMadrid()
        ->relocateTo(SiteTimezone::fromString('Atlantic/Canary'), SiteTimezoneLock::LockedByWorkRecord);

    expect($intento)->toThrow(SiteTimezoneLocked::class);

    try {
        $intento();
    } catch (SiteTimezoneLocked $locked) {
        // Un 409, no un 422: no hay nada que corregir en el formulario.
        expect($locked)->toBeInstanceOf(WorkforceConflict::class)
            ->and($locked->translationKey)->toBe('site.errors.timezone_locked')
            ->and($locked->parameters)->toBe(['timezone' => 'Europe/Madrid'])
            ->and($locked->getMessage())->toBe(
                'The site time zone is locked by the work record: it cannot change from Europe/Madrid to Atlantic/Canary by the API (RN-23).',
            );
    }
})->group('RN-23');

it('acepta la misma zona con registro horario o sin el, y devuelve el mismo centro', function (SiteTimezoneLock $lock): void {
    // El panel manda el formulario entero: guardar el nombre con la zona de
    // siempre tiene que funcionar despues del primer fichaje.
    $site = siteLockMadrid();

    expect($site->relocateTo(SiteTimezone::fromString('Europe/Madrid'), $lock))->toBe($site);
})->with([
    'sin registro' => [SiteTimezoneLock::Open],
    'con registro' => [SiteTimezoneLock::LockedByWorkRecord],
])->group('RN-23');

it('corrige la zona por consola aunque haya registro horario, sin tocar nada mas', function (): void {
    $site = siteLockMadrid()->correctTimezone(SiteTimezone::fromString('Atlantic/Canary'), siteLockFirma());

    expect($site->timezone->identifier)->toBe('Atlantic/Canary')
        ->and($site->name)->toBe('Hotel Marina')
        ->and($site->id)->toBe(1);
})->group('RN-23');

it('no corrige a la misma zona: devuelve el mismo centro', function (): void {
    $site = siteLockMadrid();

    expect($site->correctTimezone(SiteTimezone::fromString('Europe/Madrid'), siteLockFirma()))->toBe($site);
})->group('RN-23');

it('no se puede corregir la zona sin la firma de la correccion: la exige el tipo', function (): void {
    // V21-1-AR-5: que la firma no sea opcional lo dice la firma del metodo,
    // no una revision. Un segundo parametro anulable o con valor por defecto
    // dejaria corregir sin motivo ni responsable.
    $parametros = new ReflectionMethod(Site::class, 'correctTimezone')->getParameters();

    expect($parametros)->toHaveCount(2);

    $firma = $parametros[1]->getType();

    expect($firma)->toBeInstanceOf(ReflectionNamedType::class);
    \assert($firma instanceof ReflectionNamedType);

    expect($firma->getName())->toBe(SiteTimezoneCorrection::class)
        ->and($firma->allowsNull())->toBeFalse()
        ->and($parametros[1]->isOptional())->toBeFalse();
})->group('RN-23');

it('deriva el bloqueo de si existe registro horario', function (): void {
    expect(SiteTimezoneLock::fromWorkRecordPresence(true))->toBe(SiteTimezoneLock::LockedByWorkRecord)
        ->and(SiteTimezoneLock::fromWorkRecordPresence(false))->toBe(SiteTimezoneLock::Open)
        ->and(SiteTimezoneLock::LockedByWorkRecord->isLocked())->toBeTrue()
        ->and(SiteTimezoneLock::Open->isLocked())->toBeFalse();
})->group('RN-23');

it('sigue renombrando, creando y validando el centro como antes', function (): void {
    $site = siteLockMadrid()->rename('Hotel Atlantico');

    expect($site->name)->toBe('Hotel Atlantico')
        ->and($site->timezone->identifier)->toBe('Europe/Madrid')
        ->and($site->id)->toBe(1)
        ->and(Site::create('Hotel Marina', SiteTimezone::fromString('Europe/Madrid'))->id)->toBeNull()
        ->and(fn () => new Site(1, '   ', SiteTimezone::fromString('Europe/Madrid')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Site(0, 'Hotel Marina', SiteTimezone::fromString('Europe/Madrid')))->toThrow(InvalidArgumentException::class)
        ->and((new Site(1, 'Hotel Marina', SiteTimezone::fromString('Europe/Madrid')))->id)->toBe(1);
})->group('RF-GP-01', 'RN-05');

it('exige un motivo de 10 a 1000 caracteres para corregir la zona, sin los espacios de los extremos', function (): void {
    expect(SiteTimezoneCorrection::reason('  Se puso Madrid y el hotel esta en Tenerife  '))
        ->toBe('Se puso Madrid y el hotel esta en Tenerife')
        ->and(SiteTimezoneCorrection::reason(str_repeat('a', 10)))->toBe(str_repeat('a', 10))
        ->and(SiteTimezoneCorrection::reason(str_repeat('a', 1000)))->toBe(str_repeat('a', 1000))
        ->and(fn () => SiteTimezoneCorrection::reason('  '.str_repeat('a', 9).'  '))
        ->toThrow(InvalidSiteTimezoneCorrection::class, 'at least 10 characters')
        ->and(fn () => SiteTimezoneCorrection::reason(str_repeat('a', 1001)))
        ->toThrow(InvalidSiteTimezoneCorrection::class, 'at most 1000 characters');
})->group('RN-23');

it('dice por que se niega el motivo o el responsable, con la clave que lee la consola', function (): void {
    expect(InvalidSiteTimezoneCorrection::reasonTooShort(10)->translationKey)->toBe('site.correct_timezone.reason_too_short')
        ->and(InvalidSiteTimezoneCorrection::reasonTooShort(10)->parameters)->toBe(['min' => 10])
        ->and(InvalidSiteTimezoneCorrection::reasonTooLong(1000)->translationKey)->toBe('site.correct_timezone.reason_too_long')
        ->and(InvalidSiteTimezoneCorrection::reasonTooLong(1000)->parameters)->toBe(['max' => 1000])
        ->and(InvalidSiteTimezoneCorrection::responsibleNotActive(7)->translationKey)->toBe('site.correct_timezone.responsible_inactive')
        ->and(InvalidSiteTimezoneCorrection::responsibleNotActive(7)->parameters)->toBe(['id' => 7])
        ->and(InvalidSiteTimezoneCorrection::responsibleNotActive(7)->getMessage())->toBe('Account 7 is not an active management account with a role that can change the site.');
})->group('RN-23');

it('lleva en la correccion el motivo, el recuento y la cuenta que responde', function (): void {
    $correccion = SiteTimezoneCorrection::of(' Se puso Madrid y era Canarias ', 42, 7);

    expect($correccion->reason)->toBe('Se puso Madrid y era Canarias')
        ->and($correccion->shiftEntryCount)->toBe(42)
        ->and($correccion->responsibleUserId)->toBe(7)
        ->and(SiteTimezoneCorrection::of('Se puso Madrid y era Canarias', 1, 1)->shiftEntryCount)->toBe(1);
})->group('RN-23', 'RL-04');

it('no admite una correccion sin registro horario ni sin responsable', function (int $tramos, int $responsable): void {
    expect(fn () => SiteTimezoneCorrection::of('Se puso Madrid y era Canarias', $tramos, $responsable))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sin tramos' => [0, 7],
    'sin responsable' => [3, 0],
])->group('RN-23');
