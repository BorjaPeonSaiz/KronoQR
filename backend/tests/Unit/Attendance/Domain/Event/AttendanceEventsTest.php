<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Event\ScanRejected;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ScanRejectionReason;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use Tests\Support\Domain\RecordedEvents;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * Los eventos con los que Attendance habla con el resto del sistema.
 *
 * Su nombre es contrato: lo escriben `audit_log.action` (RS-07) y las metricas
 * de negocio, y no se deriva del nombre de la clase precisamente para que
 * renombrar una clase no cambie lo que ya esta escrito en un registro con valor
 * legal.
 */

it('nombra la entrada con el nombre estable que escribe la auditoria', function (): void {
    $workDay = WorkDayFactory::new()->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $clockedIn = RecordedEvents::clockedIn($workDay->releaseEvents());

    expect($clockedIn->eventName())->toBe('attendance.employee_clocked_in')
        ->and($clockedIn->occurredAt()->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T06:00:00+00:00');
})->group('RF-AT-02', 'RF-AT-09');

it('nombra la salida con el nombre estable que escribe la auditoria', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 06:00')->build();

    $workDay->clockOut(Instants::utc('2026-03-14 14:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $clockedOut = RecordedEvents::clockedOut($workDay->releaseEvents());

    expect($clockedOut->eventName())->toBe('attendance.employee_clocked_out')
        ->and($clockedOut->occurredAt()->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T14:00:00+00:00');
})->group('RF-AT-03', 'RF-AT-09');

it('nombra el recalculo del total con el nombre estable que escribe la auditoria', function (): void {
    $workDay = WorkDayFactory::new()->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $totals = RecordedEvents::dailyTotals($workDay->releaseEvents());

    expect($totals->eventName())->toBe('attendance.daily_totals_recalculated');
})->group('RN-06');

it('identifica al empleado por su uuid y nunca por su nombre', function (): void {
    // Regla dura 21: el evento se serializa en logs y en audit_log.payload, y el
    // historico de errores viaja al fabricante en el paquete de diagnostico.
    $workDay = WorkDayFactory::new()->forEmployee('0199a0c0-0000-7000-8000-00000000000a')->atSite(7)->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $clockedIn = RecordedEvents::clockedIn($workDay->releaseEvents());

    expect($clockedIn->employeeUuid)->toBe('0199a0c0-0000-7000-8000-00000000000a')
        ->and($clockedIn->siteId)->toBe(7)
        ->and($clockedIn->shiftEntryUuid)->toBe('shift-entry-1')
        ->and($clockedIn->origin)->toBe(ScanOrigin::QR_KIOSK)
        ->and(get_object_vars($clockedIn))->not->toHaveKey('displayName');
})->group('RF-AT-02');

it('lleva en la salida las dos marcas del tramo que se cierra', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 06:00')->build();

    $workDay->clockOut(Instants::utc('2026-03-14 14:00'), ScanOrigin::PIN_KIOSK, ClockingPolicyFactory::standard());
    $clockedOut = RecordedEvents::clockedOut($workDay->releaseEvents());

    expect($clockedOut->clockedInAt->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T06:00:00+00:00')
        ->and($clockedOut->clockedOutAt->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T14:00:00+00:00')
        ->and($clockedOut->origin)->toBe(ScanOrigin::PIN_KIOSK);
})->group('RF-AT-03', 'RF-AT-09');

it('nombra el escaneo rechazado y lo fecha en el momento real del escaneo', function (): void {
    // RF-AT-09: occurred_at es el del dispositivo, aunque el lote offline llegue
    // horas despues.
    $event = new ScanRejected(
        '0199a0c2-0000-7000-8000-000000000001',
        ScanRejectionReason::INVALID_SIGNATURE,
        Instants::utc('2026-03-14 06:00'),
    );

    expect($event->eventName())->toBe('attendance.scan_rejected')
        ->and($event->occurredAt()->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T06:00:00+00:00');
})->group('RS-03', 'RF-AT-09');

it('no nombra al empleado en un escaneo que no resolvio la credencial', function (): void {
    // Si la credencial no resolvio, no hay empleado que nombrar.
    $event = new ScanRejected(
        '0199a0c2-0000-7000-8000-000000000001',
        ScanRejectionReason::UNKNOWN_CREDENTIAL,
        Instants::utc('2026-03-14 06:00'),
    );

    expect($event->employeeUuid)->toBeNull()
        ->and($event->deviceId)->toBeNull()
        ->and($event->payloadFingerprint)->toBeNull();
})->group('RS-03');

it('conoce al empleado solo cuando el rechazo es el anti-rebote', function (): void {
    // Ahi la credencial es valida y acaba de funcionar hace segundos (ADR-031).
    $event = new ScanRejected(
        '0199a0c2-0000-7000-8000-000000000001',
        ScanRejectionReason::DEBOUNCE,
        Instants::utc('2026-03-14 06:00:30'),
        '0199a0c0-0000-7000-8000-00000000000a',
        3,
        'sha256:abcd',
    );

    expect($event->employeeUuid)->toBe('0199a0c0-0000-7000-8000-00000000000a')
        ->and($event->deviceId)->toBe(3)
        ->and($event->payloadFingerprint)->toBe('sha256:abcd')
        ->and($event->reason)->toBe(ScanRejectionReason::DEBOUNCE);
})->group('RF-AT-06');

it('traduce cada motivo de rechazo de credencial a su motivo de escaneo', function (CredentialRejectionReason $from, ScanRejectionReason $to): void {
    // Son dos vocabularios a proposito: el de Identity describe la credencial y
    // el de aqui describe el escaneo, que tiene un motivo mas.
    expect(ScanRejectionReason::fromCredentialRejection($from))->toBe($to);
})->with([
    'desconocida' => [CredentialRejectionReason::UNKNOWN, ScanRejectionReason::UNKNOWN_CREDENTIAL],
    'revocada' => [CredentialRejectionReason::REVOKED, ScanRejectionReason::REVOKED_CREDENTIAL],
    'mala firma' => [CredentialRejectionReason::INVALID_SIGNATURE, ScanRejectionReason::INVALID_SIGNATURE],
])->group('RS-03');

it('guarda el valor de columna de cada motivo de escaneo', function (ScanRejectionReason $reason, string $stored): void {
    // Son los de scan_events.result. El anti-rebote esta entre ellos porque el
    // escaneo se registra, aunque la respuesta HTTP sea un 200 (ADR-031).
    expect($reason->value)->toBe($stored);
})->with([
    'desconocida' => [ScanRejectionReason::UNKNOWN_CREDENTIAL, 'rejected_unknown'],
    'revocada' => [ScanRejectionReason::REVOKED_CREDENTIAL, 'rejected_revoked'],
    'mala firma' => [ScanRejectionReason::INVALID_SIGNATURE, 'rejected_signature'],
    'anti-rebote' => [ScanRejectionReason::DEBOUNCE, 'rejected_debounce'],
])->group('RS-03', 'RF-AT-06');

it('guarda el valor de columna de cada origen de marca', function (ScanOrigin $origin, string $stored): void {
    expect($origin->value)->toBe($stored);
})->with([
    'tarjeta en el quiosco' => [ScanOrigin::QR_KIOSK, 'qr_kiosk'],
    'PIN en el quiosco' => [ScanOrigin::PIN_KIOSK, 'pin_kiosk'],
    'alta manual del panel' => [ScanOrigin::MANUAL_ADMIN, 'manual_admin'],
    'importacion del sistema anterior' => [ScanOrigin::IMPORT, 'import'],
])->group('RF-AT-01', 'RF-AT-11');
