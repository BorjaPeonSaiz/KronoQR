<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Shared\Domain\ValueObject\OperationalSettings;

/*
 * Los objetos de valor que cruzan la frontera entre modulos (ADR-025).
 *
 * Dominio puro: ni framework ni base de datos. Lo que se comprueba aqui es que
 * ninguno puede representar un estado imposible, que es lo que ahorra la
 * validacion en cada sitio que los recibe.
 */

it('rechaza un perfil de cumplimiento con un umbral legal no positivo', function (int $rest, int $daily, int $break, int $retention): void {
    // Regla dura 14: los umbrales legales llegan resueltos del perfil, y un cero
    // ahi apagaria una alerta obligatoria sin que nadie se enterase.
    expect(fn (): CompliancePolicy => new CompliancePolicy($rest, $daily, $break, $retention))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sin descanso entre jornadas' => [0, 540, 360, 4],
    'sin jornada diaria' => [720, 0, 360, 4],
    'sin tramo continuo maximo' => [720, 540, 0, 4],
    'sin retencion' => [720, 540, 360, 0],
    'con retencion negativa' => [720, 540, 360, -1],
])->group('RL-02');

it('conserva los cuatro umbrales legales del perfil de cumplimiento', function (): void {
    $policy = new CompliancePolicy(720, 540, 360, 4);

    expect($policy->minimumRestMinutes)->toBe(720)
        ->and($policy->maximumDailyMinutes)->toBe(540)
        ->and($policy->breakRequiredAfterMinutes)->toBe(360)
        ->and($policy->retentionYears)->toBe(4);
})->group('RL-02');

it('acepta un umbral legal de exactamente un minuto', function (): void {
    // El limite es «menor que 1», no «menor o igual»: un perfil que fije un
    // minuto es raro, pero es valido y no puede rechazarse al arrancar.
    $policy = new CompliancePolicy(1, 1, 1, 1);

    expect($policy->minimumRestMinutes)->toBe(1)
        ->and($policy->maximumDailyMinutes)->toBe(1)
        ->and($policy->breakRequiredAfterMinutes)->toBe(1)
        ->and($policy->retentionYears)->toBe(1);
})->group('RL-02');

it('rechaza una configuracion operativa con un umbral que no puede ser cero', function (int $anomalous, int $debounce, int $skew, int $transit): void {
    expect(fn (): OperationalSettings => new OperationalSettings($anomalous, $debounce, $skew, $transit))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sin duracion anomala de tramo' => [0, 60, 10, 120],
    'sin tolerancia de desfase de reloj' => [720, 60, 0, 120],
    'con anti-rebote negativo' => [720, -1, 10, 120],
    'con transito negativo' => [720, 60, 10, -1],
])->group('RF-PD-01');

it('admite apagar el anti-rebote y el transito minimo con un cero', function (): void {
    // Cero es legitimo en los dos que desactivan una comprobacion: un centro
    // puede querer el anti-rebote apagado, o dos quioscos contiguos donde el
    // transito real es de segundos.
    $settings = new OperationalSettings(720, 0, 10, 0);

    expect($settings->debounceSeconds)->toBe(0)
        ->and($settings->minimumTransitSeconds)->toBe(0)
        ->and($settings->anomalousShiftMinutes)->toBe(720)
        ->and($settings->maximumClockSkewMinutes)->toBe(10);
})->group('RF-AT-06');

it('acepta un umbral operativo de exactamente una unidad', function (): void {
    $settings = new OperationalSettings(1, 1, 1, 1);

    expect($settings->anomalousShiftMinutes)->toBe(1)
        ->and($settings->maximumClockSkewMinutes)->toBe(1);
})->group('RF-PD-01');

it('resuelve una credencial a un empleado y a ningun motivo de rechazo', function (): void {
    $resolution = CredentialResolution::resolved('0199a0c0-0000-7000-8000-00000000000a');

    expect($resolution->isResolved())->toBeTrue()
        ->and($resolution->employeeUuid())->toBe('0199a0c0-0000-7000-8000-00000000000a')
        ->and($resolution->rejectionReason())->toBeNull();
})->group('RF-QR-02');

it('rechaza una credencial con motivo y sin empleado', function (CredentialRejectionReason $reason): void {
    // El motivo es para scan_events y las metricas, jamas para la respuesta:
    // RS-03 exige un rechazo generico y de tiempo constante.
    $resolution = CredentialResolution::rejected($reason);

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->employeeUuid())->toBeNull()
        ->and($resolution->rejectionReason())->toBe($reason);
})->with([
    'desconocida' => [CredentialRejectionReason::UNKNOWN],
    'revocada' => [CredentialRejectionReason::REVOKED],
    'mala firma' => [CredentialRejectionReason::INVALID_SIGNATURE],
])->group('RS-03');

it('no admite una credencial resuelta sin empleado detras', function (): void {
    expect(fn (): CredentialResolution => CredentialResolution::resolved(''))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-QR-02');

it('solo deja fichar al empleado activo', function (EmploymentStatus $status, bool $canClock): void {
    // RN-14: el empleado de baja conserva su historial, su credencial queda
    // revocada y sus escaneos se rechazan.
    expect($status->canClock())->toBe($canClock);
})->with([
    'activo' => [EmploymentStatus::ACTIVE, true],
    'suspendido' => [EmploymentStatus::SUSPENDED, false],
    'de baja' => [EmploymentStatus::TERMINATED, false],
])->group('RN-14');

it('pregunta al empleado si puede fichar en vez de comparar su estado por fuera', function (): void {
    $terminated = new EmployeeSnapshot('uuid-1', 'EMP-0001', 'Lucia', EmploymentStatus::TERMINATED, 1);

    expect($terminated->canClock())->toBeFalse();
})->group('RN-14');

it('describe al empleado con lo justo para decidir un fichaje', function (): void {
    $snapshot = new EmployeeSnapshot('uuid-1', 'EMP-0001', 'Lucia', EmploymentStatus::ACTIVE, 7, 3);

    expect($snapshot->employeeUuid)->toBe('uuid-1')
        ->and($snapshot->employeeCode)->toBe('EMP-0001')
        ->and($snapshot->displayName)->toBe('Lucia')
        ->and($snapshot->siteId)->toBe(7)
        ->and($snapshot->departmentId)->toBe(3)
        ->and($snapshot->canClock())->toBeTrue();
})->group('RF-AT-05');

it('admite un empleado sin departamento', function (): void {
    $snapshot = new EmployeeSnapshot('uuid-1', 'EMP-0001', 'Lucia', EmploymentStatus::ACTIVE, 7);

    expect($snapshot->departmentId)->toBeNull();
})->group('RF-GP-01');

it('admite el primer centro y el primer departamento del sistema', function (): void {
    // El limite es «menor que 1»: el identificador 1 existe, y es justo el que
    // tiene la instalacion recien sembrada.
    $snapshot = new EmployeeSnapshot('uuid-1', 'EMP-0001', 'Lucia', EmploymentStatus::ACTIVE, 1, 1);

    expect($snapshot->siteId)->toBe(1)
        ->and($snapshot->departmentId)->toBe(1);
})->group('RF-GP-01');

it('rechaza una ficha de empleado incompleta', function (string $uuid, string $code, string $name, int $siteId, ?int $departmentId): void {
    expect(fn (): EmployeeSnapshot => new EmployeeSnapshot($uuid, $code, $name, EmploymentStatus::ACTIVE, $siteId, $departmentId))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sin uuid' => ['', 'EMP-0001', 'Lucia', 1, null],
    'sin codigo de empleado' => ['uuid-1', '', 'Lucia', 1, null],
    'sin nombre para mostrar' => ['uuid-1', 'EMP-0001', '', 1, null],
    'sin centro' => ['uuid-1', 'EMP-0001', 'Lucia', 0, null],
    'con un departamento imposible' => ['uuid-1', 'EMP-0001', 'Lucia', 1, 0],
])->group('RF-GP-01');

it('guarda el valor de columna de cada situacion laboral', function (EmploymentStatus $status, string $stored): void {
    expect($status->value)->toBe($stored);
})->with([
    'activo' => [EmploymentStatus::ACTIVE, 'active'],
    'suspendido' => [EmploymentStatus::SUSPENDED, 'suspended'],
    'de baja' => [EmploymentStatus::TERMINATED, 'terminated'],
])->group('RF-GP-03');

it('guarda el valor de columna de cada motivo de rechazo de credencial', function (CredentialRejectionReason $reason, string $stored): void {
    expect($reason->value)->toBe($stored);
})->with([
    'desconocida' => [CredentialRejectionReason::UNKNOWN, 'unknown'],
    'revocada' => [CredentialRejectionReason::REVOKED, 'revoked'],
    'mala firma' => [CredentialRejectionReason::INVALID_SIGNATURE, 'invalid_signature'],
])->group('RS-03');
