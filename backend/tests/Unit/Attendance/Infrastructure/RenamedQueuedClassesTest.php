<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Attendance\Domain\Event\ShiftEntryCorrected;
use App\Modules\Attendance\Domain\ValueObject\Correction;
use App\Modules\Attendance\Domain\ValueObject\CorrectionAction;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReasonCode;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryAnomaly;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use App\Modules\Attendance\Infrastructure\Queue\RenamedQueuedClasses;
use App\Modules\Reporting\Infrastructure\Broadcasting\BroadcastPresenceChange;
use App\Modules\Reporting\Infrastructure\Listener\RecordWorkedMinutes;
use Illuminate\Events\CallQueuedListener;

/*
 * Un trabajo encolado por la 2.2.x se sigue leyendo despues de actualizar a la
 * 2.3.0, que renombro el evento de correccion, las marcas y el enum de las
 * anomalias del tramo.
 *
 * La cadena serializada se construye a mano: se serializa con el nombre de hoy
 * y se le pone el de la 2.2.x, que es exactamente lo que hay en la cola de una
 * instalacion que actualiza con trabajos pendientes.
 */

const RENAMED_QUEUED_CLASSES_OLD_EVENT = 'App\Modules\Attendance\Domain\Event\ShiftCorrected';

const RENAMED_QUEUED_CLASSES_OLD_TIMES = 'App\Modules\Attendance\Domain\ValueObject\ShiftTimes';

const RENAMED_QUEUED_CLASSES_OLD_ANOMALY = 'App\Modules\Attendance\Domain\ValueObject\ShiftAnomaly';

/**
 * La cadena de `serialize()` con los nombres de la 2.2.x: cambia el nombre de
 * cada objeto (`O:`) y de cada caso de enum (`E:`), con su longitud.
 *
 * @param  array<string, string>  $renames  nombre actual => nombre de la 2.2.x
 */
function conNombresDeLa22(string $serialized, array $renames): string
{
    $renamed = preg_replace_callback(
        '/([OE]):(\d+):"([^"]+)"/',
        static function (array $match) use ($renames): string {
            [$class, $case] = $match[1] === 'E' ? explode(':', $match[3], 2) + [1 => ''] : [$match[3], null];

            if (! isset($renames[$class])) {
                return $match[0];
            }

            $name = $renames[$class].($case === null ? '' : ':'.$case);

            return $match[1].':'.strlen($name).':"'.$name.'"';
        },
        $serialized,
    );

    expect($renamed)->not->toBe($serialized, 'La cadena no contenia ninguno de los nombres que habia que cambiar.');

    return (string) $renamed;
}

function correccionEncolable(): ShiftEntryCorrected
{
    $in = new DateTimeImmutable('2026-10-24T22:00:00Z');
    $out = new DateTimeImmutable('2026-10-25T06:00:00Z');

    return new ShiftEntryCorrected(
        employeeUuid: '0192f0a4-1111-7000-8000-000000000001',
        siteId: 1,
        workDate: WorkDate::fromIsoDate('2026-10-24', new DateTimeZone('Europe/Madrid')),
        action: CorrectionAction::MODIFIED,
        shiftEntryUuid: '0192f0a4-2222-7000-8000-000000000002',
        shiftEntryVersion: 2,
        replacementShiftEntryUuid: null,
        replacementVersion: null,
        before: ShiftEntryTimes::closed($in, $out->modify('-30 minutes')),
        after: ShiftEntryTimes::closed($in, $out),
        correction: Correction::by(7, new DateTimeImmutable('2026-10-25T08:00:00Z'), CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_SALIDA)),
        dailyTotal: WorkedDuration::ofMinutes(480),
        anomalies: [ShiftEntryAnomaly::LONG_SHIFT],
    );
}

function salidaEncolable(): EmployeeClockedOut
{
    return new EmployeeClockedOut(
        employeeUuid: '0192f0a4-1111-7000-8000-000000000001',
        siteId: 1,
        shiftEntryUuid: '0192f0a4-2222-7000-8000-000000000002',
        workDate: WorkDate::fromIsoDate('2026-10-24', new DateTimeZone('Europe/Madrid')),
        clockedInAt: new DateTimeImmutable('2026-10-24T22:00:00Z'),
        clockedOutAt: new DateTimeImmutable('2026-10-24T22:05:00Z'),
        origin: ScanOrigin::QR_KIOSK,
        worked: WorkedDuration::ofMinutes(5),
        dailyTotal: WorkedDuration::ofMinutes(5),
        anomalies: [ShiftEntryAnomaly::SHORT_SHIFT],
    );
}

it('lee el evento de correccion encolado con los nombres de la 2.2.x', function (): void {
    $event = correccionEncolable();

    $legacy = conNombresDeLa22(serialize($event), [
        ShiftEntryCorrected::class => RENAMED_QUEUED_CLASSES_OLD_EVENT,
        ShiftEntryTimes::class => RENAMED_QUEUED_CLASSES_OLD_TIMES,
        ShiftEntryAnomaly::class => RENAMED_QUEUED_CLASSES_OLD_ANOMALY,
    ]);

    expect($legacy)->toContain('"'.RENAMED_QUEUED_CLASSES_OLD_EVENT.'"')
        ->and($legacy)->toContain('"'.RENAMED_QUEUED_CLASSES_OLD_TIMES.'"')
        ->and($legacy)->toContain('"'.RENAMED_QUEUED_CLASSES_OLD_ANOMALY.':LONG_SHIFT"');

    $restored = unserialize($legacy);

    expect($restored)->toBeInstanceOf(ShiftEntryCorrected::class)
        ->and($restored)->toEqual($event);

    assert($restored instanceof ShiftEntryCorrected);
    expect($restored->after)->toBeInstanceOf(ShiftEntryTimes::class)
        ->and($restored->after?->equals(ShiftEntryTimes::closed(
            new DateTimeImmutable('2026-10-24T22:00:00Z'),
            new DateTimeImmutable('2026-10-25T06:00:00Z'),
        )))->toBeTrue()
        // Un enum deserializado es el mismo caso, no una copia.
        ->and($restored->anomalies[0])->toBe(ShiftEntryAnomaly::LONG_SHIFT);
})->group('RNF-M-03');

it('lee el caso de enum de las anomalias con el nombre de la 2.2.x', function (): void {
    $legacy = conNombresDeLa22(serialize(ShiftEntryAnomaly::SHORT_SHIFT), [
        ShiftEntryAnomaly::class => RENAMED_QUEUED_CLASSES_OLD_ANOMALY,
    ]);

    expect($legacy)->toBe('E:66:"'.RENAMED_QUEUED_CLASSES_OLD_ANOMALY.':SHORT_SHIFT";')
        ->and(unserialize($legacy))->toBe(ShiftEntryAnomaly::SHORT_SHIFT);
})->group('RNF-M-03');

it('lee los dos trabajos de los oyentes encolados, como los guarda la cola', function (string $oyente): void {
    // La forma real del trabajo: Laravel envuelve el evento en un
    // CallQueuedListener y lo serializa entero. Es lo que se deserializa en el
    // worker antes de llamar al oyente.
    [$job, $renames] = match ($oyente) {
        'presencia' => [
            new CallQueuedListener(BroadcastPresenceChange::class, 'corrected', [correccionEncolable()]),
            [
                ShiftEntryCorrected::class => RENAMED_QUEUED_CLASSES_OLD_EVENT,
                ShiftEntryTimes::class => RENAMED_QUEUED_CLASSES_OLD_TIMES,
                ShiftEntryAnomaly::class => RENAMED_QUEUED_CLASSES_OLD_ANOMALY,
            ],
        ],
        default => [
            new CallQueuedListener(RecordWorkedMinutes::class, 'handle', [salidaEncolable()]),
            [ShiftEntryAnomaly::class => RENAMED_QUEUED_CLASSES_OLD_ANOMALY],
        ],
    };

    $restored = unserialize(conNombresDeLa22(serialize($job), $renames));

    expect($restored)->toBeInstanceOf(CallQueuedListener::class)
        ->and($restored)->toEqual($job);
})->with([
    'la presencia en vivo tras una correccion' => ['presencia'],
    'las horas trabajadas tras una salida' => ['horas'],
])->group('RNF-M-03');

it('no resuelve nombres que nunca existieron', function (): void {
    // El cargador solo conoce la lista: cualquier otro nombre sigue siendo una
    // clase que no existe, y no una de las nuevas por accidente.
    expect(class_exists('App\Modules\Attendance\Domain\Event\ShiftEntryCorrectedTwice'))->toBeFalse()
        ->and(RenamedQueuedClasses::ALIASES)->toHaveCount(3)
        ->and(class_exists(RENAMED_QUEUED_CLASSES_OLD_EVENT))->toBeTrue()
        ->and(enum_exists(RENAMED_QUEUED_CLASSES_OLD_ANOMALY))->toBeTrue();
})->group('RNF-M-03');

it('registrar dos veces no apila dos cargadores', function (): void {
    $before = count(spl_autoload_functions());

    RenamedQueuedClasses::register();

    expect(spl_autoload_functions())->toHaveCount($before);
})->group('RNF-M-03');
