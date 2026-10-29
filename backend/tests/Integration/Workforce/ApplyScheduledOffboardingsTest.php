<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Shared\Application\Port\FinalWorkDayActivity;
use App\Modules\Workforce\Application\Command\OffboardEmployeeCommand;
use App\Modules\Workforce\Application\Port\OffboardingMetrics;
use App\Modules\Workforce\Application\UseCase\ApplyDueOffboardings;
use App\Modules\Workforce\Application\UseCase\OffboardEmployeeHandler;
use App\Modules\Workforce\Domain\Event\EmployeeOffboarded;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA TAREA QUE APLICA LAS BAJAS PROGRAMADAS** (RN-14, RF-GP-03, ADR-062 §4).
 *
 * `workforce:apply-scheduled-offboardings` corre cada hora y aplica la baja el
 * dia civil siguiente al cese, en la zona del centro. Se prueba:
 *
 * - **el cambio de hora**: Madrid y Canarias, a las 23:30 del dia del cese (no
 *   la aplica) y a las 00:30 del siguiente (si), en los dos domingos de 2026
 *   (29 de marzo y 25 de octubre, a la 01:00 UTC en las dos zonas). Los
 *   instantes van en UTC escritos a mano, con su hora local al lado;
 * - **el efecto**: tarjetas revocadas, portal cerrado y `employee.offboarded`
 *   con actor `system`, `scheduled`, `due_on` y `days_late`;
 * - **la idempotencia**: dos pasadas, un asiento;
 * - **tres dias sin pasar**: la fecha sigue siendo la del cese;
 * - **el aplazamiento** por un turno abierto o una pausa del ultimo dia, y su
 *   tope;
 * - **un fallo** que no detiene a las demas y que mide el *gauge*.
 */

uses(RefreshDatabase::class);

/**
 * Lo que la tarea publica, sin Redis.
 */
final class RecordingOffboardingMetricsForTheTask implements OffboardingMetrics
{
    /** @var list<string> */
    public array $processed = [];

    /** @var list<int> */
    public array $overdue = [];

    /** @var list<int> */
    public array $lastRuns = [];

    public function offboardingProcessed(string $outcome): void
    {
        $this->processed[] = $outcome;
    }

    public function overdueScheduledOffboardings(int $count): void
    {
        $this->overdue[] = $count;
    }

    public function passCompleted(DateTimeImmutable $at): void
    {
        $this->lastRuns[] = $at->getTimestamp();
    }
}

function metricasDeLaTareaDeBajas(): RecordingOffboardingMetricsForTheTask
{
    $metricas = new RecordingOffboardingMetricsForTheTask;
    app()->instance(OffboardingMetrics::class, $metricas);

    return $metricas;
}

/**
 * Una persona con la baja programada y una tarjeta activa, en un centro de la
 * zona indicada. La baja se programa por el caso de uso, con su asiento
 * `employee.offboarding_scheduled`: la tarea no aplica una baja sin el. Si el
 * reloj ya ha pasado el cese, se programa el dia anterior y se vuelve al reloj
 * de la prueba.
 *
 * @return array{site: int, employee: string, employee_id: int, device_token: string, card: string}
 */
function bajaProgramadaParaLaTarea(string $cese, string $zona = 'Europe/Madrid', bool $conTarjeta = true): array
{
    $escenario = AttendanceFixtures::scenario($zona);
    $id = AttendanceFixtures::employeeIdOf($escenario['employee']);
    // La tarjeta del fixture se emite en agosto: una baja de marzo no la puede revocar.
    $card = $conTarjeta ? Credentials::issueFor($id)->toString() : '';

    $ahora = CarbonImmutable::now()->utc();

    if ($ahora->format('Y-m-d') >= $cese) {
        FrozenTime::at(CarbonImmutable::parse($cese.' 10:00:00', 'UTC')->subDay()->format('Y-m-d H:i:s'));
    }

    app(OffboardEmployeeHandler::class)->handle(new OffboardEmployeeCommand($escenario['employee'], $cese));
    FrozenTime::at($ahora->format('Y-m-d H:i:s'));

    return [
        'site' => $escenario['site'],
        'employee' => $escenario['employee'],
        'employee_id' => $id,
        'device_token' => $escenario['token'],
        'card' => $card,
    ];
}

function ficharParaLaTareaDeBajas(string $deviceToken, string $card, string $occurredAt, string $intent = 'auto'): void
{
    $scanId = Str::uuid7()->toString();

    Api::as($deviceToken)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', ['scan_id' => $scanId, 'occurred_at' => $occurredAt, 'qr_payload' => $card, 'intent' => $intent])
        ->assertOk();
}

function estadoTrasLaTareaDeBajas(string $employee): string
{
    $status = DB::table('employees')->where('uuid', $employee)->value('status');

    return is_string($status) ? $status : '';
}

/**
 * @return list<array<string, mixed>>
 */
function asientosDeLaTareaDeBajas(): array
{
    return array_values(DB::table('audit_log')->where('action', 'employee.offboarded')->orderBy('id')->get()
        ->map(static function (object $row): array {
            /** @var object{actor_type: string, actor_id: int|null, payload: string} $row */
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

            return ['actor_type' => $row->actor_type, 'actor_id' => $row->actor_id, 'payload' => $payload];
        })->values()->all());
}

it('la aplica en el dia civil siguiente al cese en la zona del centro, tambien los dias del cambio de hora', function (string $zona, string $cese, string $ahora, string $estado): void {
    metricasDeLaTareaDeBajas();
    $baja = bajaProgramadaParaLaTarea($cese, $zona, conTarjeta: false);
    FrozenTime::at($ahora);

    app(ApplyDueOffboardings::class)->handle();

    expect(estadoTrasLaTareaDeBajas($baja['employee']))->toBe($estado)
        ->and(DB::table('employees')->where('uuid', $baja['employee'])->value('terminated_at'))->toBe($cese);
})->with([
    // 29 de marzo: Madrid pasa de UTC+1 a UTC+2 y Canarias de UTC+0 a UTC+1.
    'Madrid, 23:30 del 28 (22:30 UTC), cese el 28' => ['Europe/Madrid', '2026-03-28', '2026-03-28 22:30:00', 'active'],
    'Madrid, 00:30 del 29 (23:30 UTC del 28), cese el 28' => ['Europe/Madrid', '2026-03-28', '2026-03-28 23:30:00', 'terminated'],
    'Madrid, 23:30 del 29 ya en verano (21:30 UTC), cese el 29' => ['Europe/Madrid', '2026-03-29', '2026-03-29 21:30:00', 'active'],
    'Madrid, 00:30 del 30 (22:30 UTC del 29), cese el 29' => ['Europe/Madrid', '2026-03-29', '2026-03-29 22:30:00', 'terminated'],
    'Canarias, 23:30 del 28 (23:30 UTC), cese el 28' => ['Atlantic/Canary', '2026-03-28', '2026-03-28 23:30:00', 'active'],
    'Canarias, 00:30 del 29 (00:30 UTC), cese el 28' => ['Atlantic/Canary', '2026-03-28', '2026-03-29 00:30:00', 'terminated'],
    'Canarias, 23:30 del 29 ya en verano (22:30 UTC), cese el 29' => ['Atlantic/Canary', '2026-03-29', '2026-03-29 22:30:00', 'active'],
    'Canarias, 00:30 del 30 (23:30 UTC del 29), cese el 29' => ['Atlantic/Canary', '2026-03-29', '2026-03-29 23:30:00', 'terminated'],
    // 25 de octubre: Madrid pasa de UTC+2 a UTC+1 y Canarias de UTC+1 a UTC+0.
    'Madrid, 23:30 del 24 (21:30 UTC), cese el 24' => ['Europe/Madrid', '2026-10-24', '2026-10-24 21:30:00', 'active'],
    'Madrid, 00:30 del 25 aun en verano (22:30 UTC del 24), cese el 24' => ['Europe/Madrid', '2026-10-24', '2026-10-24 22:30:00', 'terminated'],
    'Madrid, 23:30 del 25 ya en invierno (22:30 UTC), cese el 25' => ['Europe/Madrid', '2026-10-25', '2026-10-25 22:30:00', 'active'],
    'Madrid, 00:30 del 26 (23:30 UTC del 25), cese el 25' => ['Europe/Madrid', '2026-10-25', '2026-10-25 23:30:00', 'terminated'],
    'Canarias, 23:30 del 24 (22:30 UTC), cese el 24' => ['Atlantic/Canary', '2026-10-24', '2026-10-24 22:30:00', 'active'],
    'Canarias, 00:30 del 25 aun en verano (23:30 UTC del 24), cese el 24' => ['Atlantic/Canary', '2026-10-24', '2026-10-24 23:30:00', 'terminated'],
    'Canarias, 23:30 del 25 ya en invierno (23:30 UTC), cese el 25' => ['Atlantic/Canary', '2026-10-25', '2026-10-25 23:30:00', 'active'],
    'Canarias, 00:30 del 26 (00:30 UTC), cese el 25' => ['Atlantic/Canary', '2026-10-25', '2026-10-26 00:30:00', 'terminated'],
])->group('RN-14', 'RF-GP-03');

it('revoca las tarjetas, cierra el portal y deja employee.offboarded con actor system, una sola vez', function (): void {
    $metricas = metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-10-20 10:00:00');
    $baja = bajaProgramadaParaLaTarea('2026-10-30');
    $sesion = PortalLogins::open($baja['employee']);
    FrozenTime::at('2026-10-31 09:00:00');

    $primera = app(ApplyDueOffboardings::class)->handle();
    $segunda = app(ApplyDueOffboardings::class)->handle();

    expect($primera->applied)->toBe(1)
        ->and($segunda->applied)->toBe(0)
        ->and(estadoTrasLaTareaDeBajas($baja['employee']))->toBe('terminated')
        ->and(DB::table('credentials')->where('employee_id', $baja['employee_id'])->whereNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'credential.revoked')->count())->toBe(1)
        ->and(asientosDeLaTareaDeBajas())->toEqual([[
            'actor_type' => 'system',
            'actor_id' => null,
            'payload' => [
                'employee_uuid' => $baja['employee'],
                'terminated_on' => '2026-10-30',
                'has_reason' => false,
                'scheduled' => true,
                'due_on' => '2026-10-31',
                'days_late' => 0,
            ],
        ]])
        ->and($metricas->processed)->toBe(['applied'])
        ->and($metricas->overdue)->toBe([0, 0])
        ->and(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();

    Api::as($sesion)->get('/api/v1/me/workdays')->assertStatus(401);
})->group('RN-14', 'RF-GP-03', 'RF-QR-03', 'RL-04');

it('si no corrio en tres dias la aplica con su fecha de cese y dice cuantos dias tarde', function (): void {
    metricasDeLaTareaDeBajas();
    $baja = bajaProgramadaParaLaTarea('2026-10-01');
    FrozenTime::at('2026-10-05 09:00:00');

    $informe = app(ApplyDueOffboardings::class)->handle();

    expect($informe->applied)->toBe(1)
        ->and(DB::table('employees')->where('uuid', $baja['employee'])->value('terminated_at'))->toBe('2026-10-01')
        ->and(asientosDeLaTareaDeBajas()[0]['payload'])->toMatchArray(['due_on' => '2026-10-02', 'days_late' => 3]);
})->group('RN-14', 'RF-GP-03');

it('no toca una baja programada para hoy ni una anulada', function (): void {
    metricasDeLaTareaDeBajas();
    $hoy = bajaProgramadaParaLaTarea('2026-10-31');
    FrozenTime::at('2026-10-31 09:00:00');

    $informe = app(ApplyDueOffboardings::class)->handle();

    expect($informe->applied)->toBe(0)
        ->and(estadoTrasLaTareaDeBajas($hoy['employee']))->toBe('active')
        ->and(asientosDeLaTareaDeBajas())->toBe([]);
})->group('RN-14');

it('aplaza mientras el turno de noche del ultimo dia sigue abierto, y la aplica en el tope', function (): void {
    $metricas = metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-10-30 21:05:00');
    $baja = bajaProgramadaParaLaTarea('2026-10-30');
    // 21:00 UTC del 30 son las 22:00 del 30 en Madrid: el turno de noche del cese.
    ficharParaLaTareaDeBajas($baja['device_token'], $baja['card'], '2026-10-30T21:00:00Z');

    // 00:30 del 31 en Madrid: vencida, pero la jornada del cese sigue abierta.
    FrozenTime::at('2026-10-30 23:30:00');
    $aplazada = app(ApplyDueOffboardings::class)->handle();

    // 00:30 del 1 de noviembre: el tope (cese + 2). Se aplica con el turno abierto.
    FrozenTime::at('2026-10-31 23:30:00');
    $tope = app(ApplyDueOffboardings::class)->handle();

    expect($aplazada->deferred)->toBe(1)
        ->and($tope->applied)->toBe(1)
        ->and($metricas->processed)->toBe(['deferred', 'applied'])
        ->and(estadoTrasLaTareaDeBajas($baja['employee']))->toBe('terminated')
        ->and(DB::table('shift_entries')->whereNull('clocked_out_at')->count())->toBe(1);
})->group('RN-14', 'RN-05', 'RF-GP-03');

it('aplica en cuanto la salida del turno de noche cierra la jornada del cese', function (): void {
    metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-10-30 21:05:00');
    $baja = bajaProgramadaParaLaTarea('2026-10-30');
    ficharParaLaTareaDeBajas($baja['device_token'], $baja['card'], '2026-10-30T21:00:00Z');

    FrozenTime::at('2026-10-31 05:30:00');
    ficharParaLaTareaDeBajas($baja['device_token'], $baja['card'], '2026-10-31T05:00:00Z');

    expect(app(ApplyDueOffboardings::class)->handle()->applied)->toBe(1)
        ->and(DB::table('shift_entries')->where('work_date', '2026-10-30')->whereNotNull('clocked_out_at')->count())->toBe(1);
})->group('RN-14', 'RN-05');

it('aplaza mientras la pausa del ultimo dia admite vuelta, y no despues de la cota', function (): void {
    metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-10-30 22:05:00');
    $baja = bajaProgramadaParaLaTarea('2026-10-30');
    ficharParaLaTareaDeBajas($baja['device_token'], $baja['card'], '2026-10-30T14:00:00Z');
    // Pausa a las 22:00 UTC (23:00 en Madrid) del dia del cese.
    ficharParaLaTareaDeBajas($baja['device_token'], $baja['card'], '2026-10-30T22:00:00Z', 'break_start');

    // 00:30 del 31 en Madrid, dentro de las 12 h de descanso del perfil.
    FrozenTime::at('2026-10-30 23:30:00');
    $dentro = app(ApplyDueOffboardings::class)->handle();

    // 12 h y un minuto despues de la pausa: ya no es una pausa.
    FrozenTime::at('2026-10-31 10:01:00');
    $fuera = app(ApplyDueOffboardings::class)->handle();

    expect($dentro->deferred)->toBe(1)
        ->and($fuera->applied)->toBe(1);
})->group('RN-14', 'RN-05', 'RF-AT-12');

it('un fallo no detiene a las demas, se mide y queda en el gauge pasado el tope', function (): void {
    $metricas = metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-09-20 10:00:00');
    $falla = bajaProgramadaParaLaTarea('2026-10-01');
    $otra = WorkforceFixtures::employee(WorkforceFixtures::onlySiteId());
    app(OffboardEmployeeHandler::class)->handle(new OffboardEmployeeCommand($otra, '2026-10-01'));

    app()->instance(FinalWorkDayActivity::class, new class($falla['employee']) implements FinalWorkDayActivity
    {
        public function __construct(private string $broken) {}

        public function mayStillGrow(string $employeeUuid, int $siteId, string $terminatedOn, DateTimeImmutable $at): bool
        {
            if ($employeeUuid === $this->broken) {
                throw new RuntimeException('Fallo simulado de la tarea.');
            }

            return false;
        }

        public function extendedPast(string $employeeUuid, string $terminatedOn, DateTimeImmutable $dayEndsAt): bool
        {
            return false;
        }
    });

    FrozenTime::at('2026-10-02 09:00:00');
    $aTiempo = app(ApplyDueOffboardings::class)->handle();
    $estadoTrasLaPrimera = [estadoTrasLaTareaDeBajas($falla['employee']), estadoTrasLaTareaDeBajas($otra)];

    FrozenTime::at('2026-10-05 09:00:00');
    $tarde = app(ApplyDueOffboardings::class)->handle();

    expect($aTiempo->failed)->toBe(1)
        ->and($aTiempo->applied)->toBe(1)
        ->and($aTiempo->overdue)->toBe(0)
        ->and($estadoTrasLaPrimera)->toBe(['active', 'terminated'])
        // La pregunta sigue fallando pasado el tope: queda pendiente, y el gauge
        // la cuenta.
        ->and($tarde->failed)->toBe(1)
        ->and($tarde->overdue)->toBe(1)
        ->and($metricas->processed)->toBe(['failed', 'applied', 'failed'])
        ->and($metricas->overdue)->toBe([0, 1])
        ->and($metricas->lastRuns)->toHaveCount(2);
})->group('RN-14', 'RF-GP-03');

it('cuenta en el gauge la baja que sigue sin aplicar pasado el tope, y no deja nada a medias', function (): void {
    $metricas = metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-09-20 10:00:00');
    $baja = bajaProgramadaParaLaTarea('2026-10-01');
    // Un listener que falla deshace la transaccion de esa persona entera.
    Event::listen(EmployeeOffboarded::class, static function (EmployeeOffboarded $event) use ($baja): void {
        if ($event->employeeUuid === $baja['employee']) {
            throw new RuntimeException('Fallo simulado al aplicar la baja.');
        }
    });

    // Cese el 1 y hoy el 5: el tope ya paso y sigue sin aplicar.
    FrozenTime::at('2026-10-05 09:00:00');
    $informe = app(ApplyDueOffboardings::class)->handle();

    expect($informe->failed)->toBe(1)
        ->and($informe->overdue)->toBe(1)
        ->and($metricas->overdue)->toBe([1])
        ->and(estadoTrasLaTareaDeBajas($baja['employee']))->toBe('active')
        ->and(DB::table('credentials')->where('employee_id', $baja['employee_id'])->whereNull('revoked_at')->count())->toBe(1)
        ->and(asientosDeLaTareaDeBajas())->toBe([]);
})->group('RN-14', 'RF-GP-03');

it('sin centro no hay nada que aplicar', function (): void {
    $metricas = metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-10-02 09:00:00');

    $informe = app(ApplyDueOffboardings::class)->handle();

    expect($informe->ranOverASite)->toBeFalse()
        ->and($metricas->processed)->toBe([])
        ->and($metricas->overdue)->toBe([]);
})->group('RN-14');

it('se lanza por consola, sale bien sin nada que hacer y en falso si una falla', function (): void {
    metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-10-02 09:00:00');
    $baja = bajaProgramadaParaLaTarea('2026-10-01');

    expect(Artisan::call('workforce:apply-scheduled-offboardings'))->toBe(0);

    expect(estadoTrasLaTareaDeBajas($baja['employee']))->toBe('terminated');

    $otra = WorkforceFixtures::employee(WorkforceFixtures::onlySiteId());
    DB::table('employees')->where('uuid', $otra)->update(['terminated_at' => '2026-10-01']);
    app()->instance(FinalWorkDayActivity::class, new class implements FinalWorkDayActivity
    {
        public function mayStillGrow(string $employeeUuid, int $siteId, string $terminatedOn, DateTimeImmutable $at): bool
        {
            throw new RuntimeException('Fallo simulado de la tarea.');
        }

        public function extendedPast(string $employeeUuid, string $terminatedOn, DateTimeImmutable $dayEndsAt): bool
        {
            return false;
        }
    });

    expect(Artisan::call('workforce:apply-scheduled-offboardings'))->toBe(1);
})->group('RN-14', 'RF-GP-03');

it('no aplica una fecha de cese escrita por fuera del producto, sin su asiento de programacion', function (): void {
    // ADR-062 §4: `system` no firma una baja que nadie pidio. Con la credencial
    // del runtime se podria escribir `terminated_at` directamente (T1565.001).
    $metricas = metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-09-20 10:00:00');
    $escenario = AttendanceFixtures::scenario();
    $id = AttendanceFixtures::employeeIdOf($escenario['employee']);
    Credentials::issueFor($id);
    DB::statement("UPDATE employees SET terminated_at = '2026-10-01' WHERE id = ?", [$id]);
    // Una programacion con otra fecha tampoco cubre la escrita a mano.
    $otra = bajaDeOtraFechaParaLaTarea('2026-10-03', '2026-10-01');

    FrozenTime::at('2026-10-05 09:00:00');
    $informe = app(ApplyDueOffboardings::class)->handle();

    expect($informe->failed)->toBe(2)
        ->and($informe->applied)->toBe(0)
        ->and($informe->overdue)->toBe(2)
        ->and($metricas->processed)->toBe(['failed', 'failed'])
        ->and(estadoTrasLaTareaDeBajas($escenario['employee']))->toBe('active')
        ->and(estadoTrasLaTareaDeBajas($otra))->toBe('active')
        ->and(DB::table('credentials')->where('employee_id', $id)->whereNull('revoked_at')->count())->toBe(1)
        ->and(asientosDeLaTareaDeBajas())->toBe([]);
})->group('RN-14', 'RL-04', 'RS-14');

/**
 * Una persona con la baja programada para `$programada` por el producto y la
 * fecha cambiada despues por fuera a `$escrita`.
 */
function bajaDeOtraFechaParaLaTarea(string $programada, string $escrita): string
{
    $persona = WorkforceFixtures::employee(WorkforceFixtures::onlySiteId());
    app(OffboardEmployeeHandler::class)->handle(new OffboardEmployeeCommand($persona, $programada));
    DB::table('employees')->where('uuid', $persona)->update(['terminated_at' => $escrita]);

    return $persona;
}

it('marca como aplazada la baja cuya jornada del cese siguio despues de su dia', function (): void {
    metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-10-30 21:05:00');
    $baja = bajaProgramadaParaLaTarea('2026-10-30');
    // El turno de noche del ultimo dia: de las 22:00 del 30 a las 06:00 del 31.
    ficharParaLaTareaDeBajas($baja['device_token'], $baja['card'], '2026-10-30T21:00:00Z');
    FrozenTime::at('2026-10-31 05:30:00');
    ficharParaLaTareaDeBajas($baja['device_token'], $baja['card'], '2026-10-31T05:00:00Z');

    expect(app(ApplyDueOffboardings::class)->handle()->applied)->toBe(1)
        ->and(asientosDeLaTareaDeBajas()[0]['payload'])->toMatchArray(['days_late' => 0, 'deferred' => true]);
})->group('RN-14', 'RN-05');

it('no marca como aplazada la baja cuyo ultimo dia termino en su dia', function (): void {
    metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-10-30 16:00:00');
    $baja = bajaProgramadaParaLaTarea('2026-10-30');
    ficharParaLaTareaDeBajas($baja['device_token'], $baja['card'], '2026-10-30T07:00:00Z');
    ficharParaLaTareaDeBajas($baja['device_token'], $baja['card'], '2026-10-30T15:00:00Z');
    FrozenTime::at('2026-10-30 23:30:00');

    app(ApplyDueOffboardings::class)->handle();

    expect(asientosDeLaTareaDeBajas()[0]['payload'])->not->toHaveKey('deferred');
})->group('RN-14', 'RN-05');

it('publica su latido al terminar cada pasada, tambien sin centro y sin nada que aplicar', function (): void {
    $metricas = metricasDeLaTareaDeBajas();
    FrozenTime::at('2026-10-02 09:00:00');

    app(ApplyDueOffboardings::class)->handle();

    WorkforceFixtures::site('Hotel del latido');
    FrozenTime::at('2026-10-02 10:07:00');
    app(ApplyDueOffboardings::class)->handle();

    expect($metricas->lastRuns)->toBe([
        CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC')->getTimestamp(),
        CarbonImmutable::parse('2026-10-02 10:07:00', 'UTC')->getTimestamp(),
    ]);
})->group('RN-14', 'RF-PR-04');
