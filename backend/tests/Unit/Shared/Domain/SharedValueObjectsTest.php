<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Shared\Domain\ValueObject\KioskUpdateWindow;
use App\Modules\Shared\Domain\ValueObject\OperationalSettings;

/*
 * Los objetos de valor que cruzan la frontera entre modulos (ADR-025).
 *
 * Dominio puro: ni framework ni base de datos. Lo que se comprueba aqui es que
 * ninguno puede representar un estado imposible, que es lo que ahorra la
 * validacion en cada sitio que los recibe.
 */

it('rechaza un perfil de cumplimiento con un umbral legal no positivo', function (int $rest, int $daily, int $break, int $retention, int $weekly = 2400): void {
    // Regla dura 14: los umbrales legales llegan resueltos del perfil, y un cero
    // ahi apagaria una alerta obligatoria sin que nadie se enterase.
    expect(fn (): CompliancePolicy => new CompliancePolicy($rest, $daily, $break, $retention, $weekly, 1, []))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sin descanso entre jornadas' => [0, 540, 360, 4],
    'sin jornada diaria' => [720, 0, 360, 4],
    'sin tramo continuo maximo' => [720, 540, 0, 4],
    'sin retencion' => [720, 540, 360, 0],
    'con retencion negativa' => [720, 540, 360, -1],
    // RN-17 desde la tarea 3.4. La fila la rechazan DOS guardas —el `positive()`
    // y el «la semana no puede caber por debajo del dia»— y aun asi entra: es el
    // unico caso que ejercita el quinto `positive()`, y sin ella ese umbral seria
    // el unico del perfil sin su propia frontera probada.
    'sin jornada semanal' => [720, 540, 360, 4, 0],
])->group('RL-02', 'RN-17');

it('conserva los cuatro umbrales legales del perfil de cumplimiento', function (): void {
    $policy = new CompliancePolicy(720, 540, 360, 4, 2400, 1, []);

    expect($policy->minimumRestMinutes)->toBe(720)
        ->and($policy->maximumDailyMinutes)->toBe(540)
        ->and($policy->breakRequiredAfterMinutes)->toBe(360)
        ->and($policy->retentionYears)->toBe(4);
})->group('RL-02');

it('acepta un umbral legal de exactamente un minuto', function (): void {
    // El limite es «menor que 1», no «menor o igual»: un perfil que fije un
    // minuto es raro, pero es valido y no puede rechazarse al arrancar.
    $policy = new CompliancePolicy(1, 1, 1, 1, 1, 1, []);

    expect($policy->minimumRestMinutes)->toBe(1)
        ->and($policy->maximumDailyMinutes)->toBe(1)
        ->and($policy->breakRequiredAfterMinutes)->toBe(1)
        ->and($policy->retentionYears)->toBe(1);
})->group('RL-02');

it('rechaza un perfil que empieza la semana fuera de la numeracion ISO-8601', function (int $weekStartsOn): void {
    // 1 es lunes y 7 domingo. Un 0 o un 8 no es «otro convenio»: es un dato que
    // ningun informe semanal puede interpretar, y la tarea 3.4 agrupara por el.
    expect(fn (): CompliancePolicy => new CompliancePolicy(720, 540, 360, 4, 2400, $weekStartsOn, []))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'el dia cero' => [0],
    'el octavo dia' => [8],
    'un dia negativo' => [-1],
])->group('RF-PD-07');

it('acepta los siete dias de la semana como inicio', function (int $weekStartsOn): void {
    expect((new CompliancePolicy(720, 540, 360, 4, 2400, $weekStartsOn, []))->weekStartsOn)->toBe($weekStartsOn);
})->with([1, 2, 3, 4, 5, 6, 7])->group('RF-PD-07');

it('rechaza un perfil cuya jornada semanal cabe por debajo de la diaria', function (): void {
    // Nadie puede trabajar mas en un dia que en una semana. La misma invariante
    // la sostiene un CHECK del esquema; aqui esta porque el dominio no puede dar
    // por hecho que quien lo construye venga de la base de datos.
    expect(fn (): CompliancePolicy => new CompliancePolicy(720, 540, 360, 4, 539, 1, []))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-PD-07');

it('acepta la jornada semanal exactamente igual a la diaria', function (): void {
    // El limite es «menor que», no «menor o igual»: un contrato de un solo dia a
    // la semana es raro y es valido.
    expect((new CompliancePolicy(720, 540, 360, 4, 540, 1, []))->maximumWeeklyMinutes)->toBe(540);
})->group('RF-PD-07');

it('descarta los festivos que no son fechas ISO en vez de estallar', function (string $day): void {
    // **Cambio deliberado de la revision de la 5.2.** Antes lanzaba, y eso
    // tumbaba la pasada nocturna de deteccion entera: se resuelve la politica una
    // vez, antes del bucle y sin `try`, asi que un `'["navidad"]'` escrito a mano
    // en la columna dejaba sin evaluar RN-10 y RN-11 en toda la instalacion (y se
    // llevaba por delante la purga por retencion). Un dato que hoy no lee ninguna
    // regla no puede apagar dos que si (regla dura 19).
    //
    // La comprobacion estricta no desaparece: vive en el camino de **escritura**,
    // en `Product`, donde hay alguien delante a quien devolverle un `422`.
    expect((new CompliancePolicy(720, 540, 360, 4, 2400, 1, [$day]))->holidayCalendar)->toBe([]);
})->with([
    'texto libre' => ['Navidad'],
    'formato espanol' => ['25/12/2026'],
    'dia que no existe' => ['2026-02-30'],
    'mes que no existe' => ['2026-13-01'],
    'cadena vacia' => [''],
])->group('RF-PD-07');

it('ordena el calendario de festivos y quita los repetidos', function (): void {
    // Dos perfiles con los mismos festivos en distinto orden son el mismo perfil.
    // Sin normalizar, reordenar la lista produciria un asiento de auditoria que
    // declara un cambio de umbral legal donde no lo hubo.
    $policy = new CompliancePolicy(720, 540, 360, 4, 2400, 1, [
        '2026-12-25', '2026-01-01', '2026-12-25', '2026-08-15',
    ]);

    expect($policy->holidayCalendar)->toBe(['2026-01-01', '2026-08-15', '2026-12-25']);
})->group('RF-PD-07');

it('acepta el calendario vacio, que es el valor de serie del perfil espanol', function (): void {
    // Los festivos son del centro y del ano: un calendario concreto en el
    // producto caducaria el 31 de diciembre. Lo carga el cliente.
    expect((new CompliancePolicy(720, 540, 360, 4, 2400, 1, []))->holidayCalendar)->toBe([]);
})->group('RF-PD-07');

it('conserva la jornada semanal, el inicio de semana y los festivos', function (): void {
    // `maximumWeeklyMinutes` y `weekStartsOn` los estreno RN-17 en la tarea 3.4;
    // `holidayCalendar` sigue sin consumidor a proposito hasta la 3.10. Que nadie
    // lea uno de ellos no lo hace decorativo: se guarda, se valida y se audita
    // desde la 5.2, y esta prueba fija que llegan enteros al dominio.
    $policy = new CompliancePolicy(720, 540, 360, 4, 2400, 1, ['2026-01-06']);

    expect($policy->maximumWeeklyMinutes)->toBe(2400)
        ->and($policy->weekStartsOn)->toBe(1)
        ->and($policy->holidayCalendar)->toBe(['2026-01-06']);
})->group('RF-PD-07');

it('rechaza una configuracion operativa con un umbral que no puede ser cero', function (int $anomalous, int $debounce, int $skew, int $transit, int $window, int $repeats): void {
    expect(fn (): OperationalSettings => new OperationalSettings(
        $anomalous, $debounce, $skew, $transit, $window, $repeats,
        breakClockingEnabled: false,
        kioskUpdateWindow: KioskUpdateWindow::fromRange('03:00-05:00'),
        kioskUpdateQuietMinutes: 10,
        baselineManualHoursPerMonth: 0,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'sin duracion anomala de tramo' => [0, 60, 10, 120, 10, 3],
    'sin tolerancia de desfase de reloj' => [720, 60, 0, 120, 10, 3],
    'con anti-rebote negativo' => [720, -1, 10, 120, 10, 3],
    'con transito negativo' => [720, 60, 10, -1, 10, 3],
    // RF-PR-06 (tarea 3.11). La ventana de coincidencia admite el cero —apaga
    // el hallazgo— pero no un negativo; los dias con coincidencia no admiten el
    // cero: «sistematico» con cero repeticiones no significa nada, y para apagar
    // el hallazgo esta la ventana.
    'con ventana de coincidencia negativa' => [720, 60, 10, 120, -1, 3],
    'sin dias con coincidencia que exigir' => [720, 60, 10, 120, 10, 0],
])->group('RF-PD-01');

it('admite apagar el anti-rebote y el transito minimo con un cero', function (): void {
    // Cero es legitimo en los dos que desactivan una comprobacion: un centro
    // puede querer el anti-rebote apagado, o dos quioscos contiguos donde el
    // transito real es de segundos.
    $settings = new OperationalSettings(
        720, 0, 10, 0, 0, 1,
        breakClockingEnabled: false,
        kioskUpdateWindow: KioskUpdateWindow::fromRange('03:00-05:00'),
        // RF-KI-07 (tarea 3.12): el cuarto que admite el cero. Apaga la guarda
        // de silencio y deja mandar a la franja y a la cola vacia.
        kioskUpdateQuietMinutes: 0,
        baselineManualHoursPerMonth: 0,
    );

    expect($settings->kioskUpdateQuietMinutes)->toBe(0)
        ->and($settings->debounceSeconds)->toBe(0)
        ->and($settings->minimumTransitSeconds)->toBe(0)
        // RF-PR-06: la tercera que admite el cero, y lo apaga igual que las
        // otras dos (tarea 3.11).
        ->and($settings->patternWindowSeconds)->toBe(0)
        ->and($settings->anomalousShiftMinutes)->toBe(720)
        ->and($settings->maximumClockSkewMinutes)->toBe(10);
})->group('RF-AT-06');

it('acepta un umbral operativo de exactamente una unidad', function (): void {
    $settings = new OperationalSettings(
        1, 1, 1, 1, 1, 1,
        breakClockingEnabled: true,
        kioskUpdateWindow: KioskUpdateWindow::fromRange('03:00-05:00'),
        kioskUpdateQuietMinutes: 1,
        baselineManualHoursPerMonth: 0,
    );

    expect($settings->anomalousShiftMinutes)->toBe(1)
        ->and($settings->maximumClockSkewMinutes)->toBe(1)
        ->and($settings->patternMinRepeats)->toBe(1);
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

it('transporta el fichaje de pausa sin suponer ningun valor', function (bool $enabled): void {
    // RF-AT-12. **Sin valor por defecto** (regla dura 14): el valor de serie
    // —`disabled`— vive en el catalogo de `SettingKey` y lo resuelve el
    // adaptador. Un defecto aqui seria una segunda fuente para el mismo dato, y
    // la que ganaria en silencio el dia que el adaptador se olvidara de leer la
    // clave: el hotel activaria la pausa en el panel y el quiosco seguiria sin
    // ofrecerla.
    $settings = new OperationalSettings(
        720, 60, 15, 120, 10, 3,
        breakClockingEnabled: $enabled,
        kioskUpdateWindow: KioskUpdateWindow::fromRange('03:00-05:00'),
        kioskUpdateQuietMinutes: 10,
        baselineManualHoursPerMonth: 0,
    );

    expect($settings->breakClockingEnabled)->toBe($enabled);
})->with([
    'activado' => [true],
    'desactivado' => [false],
])->group('RF-PD-01', 'RF-AT-12');
