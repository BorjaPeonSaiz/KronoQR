<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Compliance\Infrastructure\Persistence\DatabasePersonnelRecordArchive;
use App\Modules\Identity\Application\UseCase\RevokeCredentialsOfOffboardedEmployee;
use App\Modules\Shared\Application\Port\PortalSessionIssuer;
use App\Modules\Shared\Domain\ValueObject\PortalSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\Credentials;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `credentials:revoke-offboarded` (R3-AR-07, R4-SC-11; tarea 12.4 de la 2.3.0):
 * las bajas registradas antes de la 2.2.0 conservaban sus tarjetas activas,
 * porque la revocacion en la baja llego con la 2.2.0. La orden las revoca con el
 * mismo motivo y el mismo asiento que la baja de hoy, con la fecha de la
 * ejecucion, y no toca nada mas. La lanza `update.sh` tras migrar.
 *
 * Las bajas se crean con `WorkforceFixtures::employee(..., 'terminated')`, sin
 * pasar por el caso de uso: es exactamente como quedaron las de antes.
 */

uses(RefreshDatabase::class);

const REVOKE_OFFBOARDED_NOW = '2026-10-22 09:15:00';

beforeEach(function (): void {
    FrozenTime::at(REVOKE_OFFBOARDED_NOW);
});

/**
 * @return array{site: int, bajaId: int, bajaUuid: string, altaId: int}
 */
function bajasConTarjeta(): array
{
    $escenario = AttendanceFixtures::scenario();
    $baja = WorkforceFixtures::employee($escenario['site'], $escenario['department'], 'terminated');
    $bajaId = AttendanceFixtures::employeeIdOf($baja);
    $altaId = AttendanceFixtures::employeeIdOf($escenario['employee']);

    // La tarjeta en la mano y una reemision pendiente de imprimir; y una que ya
    // estaba revocada por perdida, que no se toca.
    Credentials::issueFor($bajaId);
    Credentials::pendingFor($bajaId);
    Credentials::issueFor($bajaId, Credentials::previousKey(), 'perdida');
    // Quien sigue de alta conserva la suya.
    Credentials::issueFor($altaId);

    return ['site' => $escenario['site'], 'bajaId' => $bajaId, 'bajaUuid' => $baja, 'altaId' => $altaId];
}

function revocarBajas(): int
{
    return Artisan::call('credentials:revoke-offboarded');
}

it('revoca las tarjetas activas de las bajas con el motivo de la baja, la fecha de hoy y un asiento cada una', function (): void {
    $escenario = bajasConTarjeta();

    expect(revocarBajas())->toBe(0)
        ->and(trim(Artisan::output()))->toBe('revoked_credentials=2 employees=1 failed=0');

    $tarjetas = DB::table('credentials')->where('employee_id', $escenario['bajaId'])->get();
    $revocadasHoy = $tarjetas->where('revoked_reason', RevokeCredentialsOfOffboardedEmployee::REASON);

    expect($tarjetas->whereNull('revoked_at'))->toHaveCount(0)
        ->and($revocadasHoy)->toHaveCount(2)
        ->and($tarjetas->where('revoked_reason', 'perdida'))->toHaveCount(1);

    // No se antedata: la revocacion es de cuando se hizo, no del cese.
    foreach ($revocadasHoy as $tarjeta) {
        expect((new DateTimeImmutable((string) $tarjeta->revoked_at))->format('Y-m-d H:i:s'))->toBe(REVOKE_OFFBOARDED_NOW);
    }

    $asientos = DB::table('audit_log')->where('action', 'credential.revoked')->get();

    expect($asientos)->toHaveCount(2);

    foreach ($asientos as $asiento) {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $asiento->payload, true, flags: JSON_THROW_ON_ERROR);

        expect($payload['employee_uuid'])->toBe($escenario['bajaUuid'])
            ->and($payload['reason'])->toBe(RevokeCredentialsOfOffboardedEmployee::REASON);
    }

    expect(DB::table('credentials')->where('employee_id', $escenario['altaId'])->whereNull('revoked_at')->count())->toBe(1)
        ->and(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RN-14', 'RF-GP-03', 'RF-QR-03', 'RL-04');

it('es idempotente: la segunda ejecucion no revoca ni asienta nada', function (): void {
    bajasConTarjeta();
    revocarBajas();
    $asientos = DB::table('audit_log')->count();

    expect(revocarBajas())->toBe(0)
        ->and(trim(Artisan::output()))->toBe('revoked_credentials=0 employees=0 failed=0')
        ->and(DB::table('audit_log')->count())->toBe($asientos);
})->group('RN-14', 'RL-04');

it('no imprime nombres ni identificadores de personas', function (): void {
    $escenario = bajasConTarjeta();
    DB::table('employees')->where('id', $escenario['bajaId'])->update(['first_name' => 'Lucia', 'last_name' => 'Ferrer']);

    revocarBajas();
    $salida = Artisan::output();

    expect($salida)->not->toContain('Lucia')
        ->and($salida)->not->toContain('Ferrer')
        ->and($salida)->not->toContain($escenario['bajaUuid']);
})->group('RL-04');

it('revoca tambien la tarjeta activa de una ficha ya minimizada sin chocar con su barrera', function (): void {
    // ADR-060 (bloque 11): la ficha minimizada esta congelada por un trigger. La
    // revocacion solo escribe en `credentials` (`revoked_at`, `revoked_reason`)
    // con un motivo fijo y sin datos de la persona, asi que no la toca.
    $escenario = bajasConTarjeta();
    DB::table('employees')->where('id', $escenario['bajaId'])
        ->update(['hired_at' => '2010-01-01', 'terminated_at' => '2015-06-30']);

    /** @var object{today: string} $hoy */
    $hoy = DB::selectOne("SELECT ((now() AT TIME ZONE 'UTC')::date - interval '4 years')::date::text AS today");
    DB::select(
        'SELECT employee_uuid FROM public.'.DatabasePersonnelRecordArchive::ANONYMIZE_FUNCTION.'(?::date, 100,'
        .' ARRAY(SELECT c.id FROM public.personnel_record_retention_candidates(?::date) c))',
        [$hoy->today, $hoy->today],
    );

    expect(DB::table('employees')->where('id', $escenario['bajaId'])->value('anonymized_at'))->not->toBeNull();

    expect(revocarBajas())->toBe(0)
        ->and(DB::table('credentials')->where('employee_id', $escenario['bajaId'])->whereNull('revoked_at')->count())->toBe(0)
        ->and(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RN-14', 'RL-11', 'RL-04');

it('una persona que falla no impide revocar a las demas, y la orden sale con 1 sin el mensaje', function (): void {
    $escenario = bajasConTarjeta();
    $otra = WorkforceFixtures::employee($escenario['site'], null, 'terminated');
    $otraId = AttendanceFixtures::employeeIdOf($otra);
    Credentials::issueFor($otraId);

    // El cierre del portal de la primera baja falla dentro de su transaccion:
    // sus dos tarjetas tienen que quedar como estaban, sin asiento.
    $real = app(PortalSessionIssuer::class);
    app()->instance(PortalSessionIssuer::class, new readonly class($real, $escenario['bajaUuid']) implements PortalSessionIssuer
    {
        public function __construct(private PortalSessionIssuer $real, private string $falla) {}

        public function issueFor(string $employeeUuid, string $sessionName, array $abilities, DateTimeImmutable $expiresAt): ?PortalSession
        {
            return $this->real->issueFor($employeeUuid, $sessionName, $abilities, $expiresAt);
        }

        public function revokeAllFor(string $employeeUuid): int
        {
            if ($employeeUuid === $this->falla) {
                throw new RuntimeException('Fallo de prueba con el uuid '.$employeeUuid);
            }

            return $this->real->revokeAllFor($employeeUuid);
        }
    });

    expect(revocarBajas())->toBe(1);
    $salida = Artisan::output();

    expect($salida)->toContain('revoked_credentials=1 employees=1 failed=1')
        ->and($salida)->toContain('RuntimeException')
        ->and($salida)->not->toContain($escenario['bajaUuid'])
        ->and(DB::table('credentials')->where('employee_id', $otraId)->whereNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('credentials')->where('employee_id', $escenario['bajaId'])->whereNull('revoked_at')->count())->toBe(2)
        ->and(DB::table('audit_log')->where('action', 'credential.revoked')->count())->toBe(1)
        ->and(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RN-14', 'RL-04');
