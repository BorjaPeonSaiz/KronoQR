<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\AuditTrail;
use App\Modules\Compliance\Application\UseCase\PlanRetention;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditTrail;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `compliance:apply-retention` minimiza las cuentas de gestion vencidas
 * (ADR-060, tarea 11.3 B; RL-11, RL-04, RS-05): la simulacion las cuenta sin
 * decir de quien; la ejecucion, firmada por admin o rrhh, deja un asiento
 * `retention.management_account_anonymized` por cuenta en la misma transaccion,
 * y si el asiento falla no se minimiza nada.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $directory = sys_get_temp_dir().'/kronoqr-account-retention-'.bin2hex(random_bytes(6));

    Config::set('compliance.retention.report_path', $directory.'/informes');
    Config::set('compliance.retention.technical_log_path', $directory.'/logs');
    Config::set('observability.metrics.textfile_path', $directory.'/metricas');
    app()->setLocale('es');

    WorkforceFixtures::site();

    /** @var object{today: string} $row */
    $row = DB::selectOne("SELECT ((now() AT TIME ZONE 'UTC')::date)::text AS today");
    FrozenTime::at($row->today.' 04:00:00');
});

/** Una cuenta de baja desde 2020, sin asientos. */
function cuentaVencida(string $email = 'vieja@hotel.example'): int
{
    $user = ManagementUsers::withRole(UserRole::RRHH);
    DB::table('users')->where('id', $user->id)->update([
        'email' => $email,
        'name' => 'Persona Antigua',
        'is_active' => false,
        'deactivated_at' => '2020-01-15 10:00:00+00',
    ]);

    return $user->id;
}

/**
 * @param  array<string, mixed>  $extra
 * @return array{code: int, output: string}
 */
function retencionDeCuentas(bool $simular, array $extra = []): array
{
    $responsable = ManagementUsers::withRole(UserRole::ADMIN);

    $code = Artisan::call('compliance:apply-retention', $simular ? ['--dry-run' => true] : [
        '--confirm' => app(PlanRetention::class)->handle(app(Clock::class)->now())->confirmationToken(),
        '--responsible' => $responsable->email,
        '--responsible-confirm' => $responsable->email,
        '--no-interaction' => true,
        ...$extra,
    ]);

    return ['code' => $code, 'output' => Artisan::output()];
}

it('la simulacion cuenta las cuentas vencidas sin decir de quien y no toca nada', function (): void {
    $id = cuentaVencida();

    $orden = retencionDeCuentas(true);

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and($orden['output'])->toContain('Cuentas de gestion')
        ->and($orden['output'])->not->toContain('vieja@hotel.example')
        ->and($orden['output'])->not->toContain('Persona Antigua')
        ->and(DB::table('users')->where('id', $id)->value('anonymized_at'))->toBeNull();
})->group('RL-11', 'RF-PR-03');

it('la ejecucion minimiza la cuenta y deja su asiento a nombre de quien firma', function (): void {
    $id = cuentaVencida();
    $uuid = DB::table('users')->where('id', $id)->value('uuid');
    $uuid = is_string($uuid) ? $uuid : '';

    $orden = retencionDeCuentas(false);

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and(DB::table('users')->where('id', $id)->value('name'))->toBe('')
        ->and(DB::table('users')->where('id', $id)->value('anonymized_at'))->not->toBeNull();

    /** @var object{actor_type: string, actor_id: int, subject_type: string, subject_id: int, payload: string} $asiento */
    $asiento = DB::table('audit_log')->where('action', AuditAction::RetentionManagementAccountAnonymized->value)->first();

    /** @var array<string, mixed> $payload */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->actor_type)->toBe('user')
        ->and($asiento->subject_type)->toBe('user')
        ->and($asiento->subject_id)->toBe($id)
        ->and($payload)->toMatchArray([
            'user_uuid' => $uuid,
            'deactivated_on' => '2020-01-15',
            'retention_years' => 4,
            'tokens_deleted' => 0,
        ])
        ->and($payload['fields'] ?? [])->toContain('name', 'email', 'password')
        ->and($payload)->toHaveKeys(['cutoff_date', 'confirmation'])
        ->and($asiento->payload)->not->toContain('vieja@hotel.example')
        ->and($asiento->payload)->not->toContain('Persona Antigua');
})->group('RL-11', 'RL-04', 'RS-05', 'RF-PR-03');

it('el acceso con el correo de una cuenta minimizada da el mismo 401 que una cuenta inexistente', function (string $password): void {
    $id = cuentaVencida();
    retencionDeCuentas(false);

    $correo = DB::table('users')->where('id', $id)->value('email');
    $correo = \is_string($correo) ? $correo : '';

    $minimizada = Api::guest()->post('/api/v1/auth/login', ['email' => $correo, 'password' => $password]);
    $inexistente = Api::guest()->post('/api/v1/auth/login', ['email' => 'nadie@hotel.example', 'password' => $password]);

    $cuerpo = static function (TestResponse $respuesta): mixed {
        $json = $respuesta->json();
        \is_array($json) && \array_key_exists('instance', $json) ? $json['instance'] = '-' : null;

        return $json;
    };

    expect(str_starts_with($correo, 'anonymized+'))->toBeTrue()
        ->and($minimizada->status())->toBe(401)
        ->and($inexistente->status())->toBe(401)
        ->and($cuerpo($minimizada))->toBe($cuerpo($inexistente));
})->with([
    'la marca que deja la minimizacion' => ['!'],
    'una cualquiera' => ['una-contrasena-cualquiera'],
])->group('RL-11', 'RS-03');

it('si el asiento falla, no se minimiza ninguna cuenta', function (): void {
    $id = cuentaVencida();

    app()->instance(AuditTrail::class, new class(new DatabaseAuditTrail(DB::connection())) implements AuditTrail
    {
        public function __construct(private readonly AuditTrail $trail) {}

        public function append(AuditEntryDraft $draft): AuditEntry
        {
            if ($draft->action->value === AuditAction::RetentionManagementAccountAnonymized->value) {
                throw new RuntimeException('audit_log no responde');
            }

            return $this->trail->append($draft);
        }
    });

    expect(static fn (): array => retencionDeCuentas(false))->toThrow(RuntimeException::class, 'audit_log no responde');

    expect(DB::table('users')->where('id', $id)->value('anonymized_at'))->toBeNull()
        ->and(DB::table('users')->where('id', $id)->value('name'))->toBe('Persona Antigua');
})->group('RL-11', 'RL-04');

it('solo firma la ejecucion una cuenta admin o rrhh', function (UserRole $rol): void {
    $id = cuentaVencida();
    $otra = ManagementUsers::withRole($rol);

    $orden = retencionDeCuentas(false, ['--responsible' => $otra->email, '--responsible-confirm' => $otra->email]);

    expect($orden['code'])->toBe(2, $orden['output'])
        ->and(DB::table('users')->where('id', $id)->value('anonymized_at'))->toBeNull();
})->with([
    'auditor' => [UserRole::AUDITOR],
    'responsable' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RL-11', 'RF-PR-03');
