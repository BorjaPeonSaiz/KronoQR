<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\AuditTrail;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditTrail;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Compliance\AuditPayloads;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La suscripcion a la presencia en vivo por Reverb deja asiento (tarea 11.4,
 * R4-BE-05; RS-05, RL-15, enmienda de ADR-037): `personal_data.accessed` con
 * conjunto `live_presence_stream`, el canal y el alcance, sin nombres; agrupado
 * por actor y canal; una denegacion por alcance deja `access.denied` y no
 * `accessed`; y si el asiento no se puede escribir, no hay suscripcion.
 *
 * Mismo montaje que `PresenceChannelAuthorizationTest`: el difusor de Reverb de
 * verdad, los canales de produccion recargados y una licencia que concede el
 * tiempo real.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('identity.two_factor.required_roles', []);
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'kronoqr-test-key');
    config()->set('broadcasting.connections.reverb.secret', 'kronoqr-test-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'kronoqr-test');

    require base_path('routes/channels.php');

    LicenseKeys::grantAll();
});

/**
 * @return array{cocina: int, recepcion: int, jefe: string, rrhh: string}
 */
function presenciaAuditada(): array
{
    $site = WorkforceFixtures::site('Hotel auditado', 'Europe/Madrid');
    $jefe = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefe->id]);
    WorkforceFixtures::employee($site, $cocina, 'active', 'Lucia');

    return [
        'cocina' => $cocina,
        'recepcion' => WorkforceFixtures::department($site, 'Recepcion'),
        'jefe' => ManagementUsers::tokenFor($jefe),
        'rrhh' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
    ];
}

/**
 * @return TestResponse<Response>
 */
function suscribirPresencia(string $token, string $canal): TestResponse
{
    return Api::as($token)->post('/api/v1/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-'.$canal,
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function asientosDeSuscripcion(): array
{
    return array_values(DB::table('audit_log')
        ->where('action', 'personal_data.accessed')
        ->where('payload->dataset', 'live_presence_stream')
        ->orderBy('id')
        ->pluck('payload')
        ->map(static fn (mixed $payload): array => AuditPayloads::decode($payload))
        ->all());
}

it('deja asiento de la suscripcion al canal global, con canal y alcance y sin nombres', function (): void {
    $escenario = presenciaAuditada();

    suscribirPresencia($escenario['rrhh'], 'presence.all')->assertOk();

    $asientos = asientosDeSuscripcion();

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0])->toMatchArray([
            'dataset' => 'live_presence_stream',
            'record_count' => 0,
            'channel' => 'all',
            'scope' => 'all',
        ])
        ->and(json_encode($asientos[0]))->not->toContain('Lucia');
})->group('RS-05', 'RL-15');

it('deja asiento de la suscripcion de un responsable a su departamento', function (): void {
    $escenario = presenciaAuditada();

    suscribirPresencia($escenario['jefe'], 'presence.department.'.$escenario['cocina'])->assertOk();

    expect(asientosDeSuscripcion()[0] ?? [])->toMatchArray([
        'channel' => 'department',
        'department_id' => $escenario['cocina'],
        'scope' => 'departments',
    ]);
})->group('RS-05', 'RL-15', 'RF-ID-03');

it('agrupa las reconexiones de la ventana en un asiento, y separa los canales', function (): void {
    $escenario = presenciaAuditada();

    foreach ([1, 2, 3] as $vez) {
        suscribirPresencia($escenario['rrhh'], 'presence.all')->assertOk();
        Auth::forgetGuards();
    }

    expect(asientosDeSuscripcion())->toHaveCount(1);

    suscribirPresencia($escenario['rrhh'], 'presence.department.'.$escenario['cocina'])->assertOk();

    expect(asientosDeSuscripcion())->toHaveCount(2);
})->group('RS-05', 'RL-15');

it('una suscripcion fuera de alcance deja access.denied y no accessed', function (): void {
    $escenario = presenciaAuditada();
    $antes = DB::table('audit_log')->where('action', 'access.denied')->count();

    suscribirPresencia($escenario['jefe'], 'presence.department.'.$escenario['recepcion'])->assertStatus(403);

    expect(asientosDeSuscripcion())->toBe([])
        ->and(DB::table('audit_log')->where('action', 'access.denied')->count())->toBe($antes + 1);
})->group('RS-05', 'RF-ID-03');

it('si el asiento no se puede escribir, no hay suscripcion', function (): void {
    $escenario = presenciaAuditada();

    app()->instance(PersonalDataAccessLog::class, new class implements PersonalDataAccessLog
    {
        public function recordDisclosure(string $dataset, int $recordCount, array $context = []): void
        {
            throw new RuntimeException('audit_log no responde');
        }
    });

    $respuesta = suscribirPresencia($escenario['rrhh'], 'presence.all');

    expect($respuesta->status())->not->toBe(200)
        ->and($respuesta->json('auth'))->toBeNull();
})->group('RS-05', 'RL-15');

it('si el asiento agrupado falla, la plaza se libera y la siguiente suscripcion si lo deja', function (): void {
    $escenario = presenciaAuditada();

    // El fallo va en la cadena de verdad, debajo del decorador que agrupa: es
    // ahi donde la plaza de la ventana se habia quedado tomada sin asiento.
    app()->instance(AuditTrail::class, new class(new DatabaseAuditTrail(DB::connection())) implements AuditTrail
    {
        private int $failures = 1;

        public function __construct(private readonly AuditTrail $trail) {}

        public function append(AuditEntryDraft $draft): AuditEntry
        {
            if ($draft->action->value === AuditAction::PersonalDataAccessed->value && $this->failures > 0) {
                $this->failures--;

                throw new RuntimeException('audit_log no responde');
            }

            return $this->trail->append($draft);
        }
    });

    expect(suscribirPresencia($escenario['rrhh'], 'presence.all')->status())->toBe(500)
        ->and(asientosDeSuscripcion())->toBe([]);

    Auth::forgetGuards();

    suscribirPresencia($escenario['rrhh'], 'presence.all')->assertOk();

    expect(asientosDeSuscripcion())->toHaveCount(1);
})->group('RS-05', 'RL-15');

it('niega el canal por rol sin dejar asiento de lectura', function (UserRole $rol): void {
    presenciaAuditada();
    Auth::forgetGuards();

    suscribirPresencia(ManagementUsers::tokenFor(ManagementUsers::withRole($rol)), 'presence.all')->assertStatus(403);

    expect(asientosDeSuscripcion())->toBe([]);
})->with([
    'auditor' => [UserRole::AUDITOR],
    'empleado' => [UserRole::EMPLEADO],
])->group('RS-05', 'RQ-07');
