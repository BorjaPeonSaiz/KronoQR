<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `compliance:reconcile-work-record --acknowledge=<id>`: dar por revisada una
 * purga del registro horario que la conciliacion juzga no admisible (RL-04,
 * RS-07, ADR-057 enmienda del 10-10-2026 §6).
 *
 * La orden escribe un asiento `retention.purge_reviewed` y nada mas: la purga
 * sigue en `audit_log`. Lo que estas pruebas fijan son los codigos de salida
 * que promete el runbook, que cada negativa no escribe nada y que el asiento
 * lleva exactamente lo que el diseño dice.
 *
 * El recorrido completo (la purga se denuncia, se revisa, deja de contar y la
 * cadena sigue integra) esta en
 * tests/Integration/Compliance/WorkRecordReconciliationTest.php.
 */

uses(RefreshDatabase::class);

const RECONCILE_ACKNOWLEDGE_MOTIVO = 'INC-2027-14: el perfil se bajo a 3 años por error; se ha subido a 4.';

beforeEach(function (): void {
    // Dentro de las particiones de audit_log del esquema de pruebas.
    FrozenTime::at('2027-10-08 05:00:00');
    // Los textos de la orden salen en el idioma de la instalacion; aqui se
    // fijan en español y la ultima prueba comprueba el ingles.
    app()->setLocale('es');
});

/**
 * Un asiento de purga del registro horario como el que escribe
 * `compliance:apply-retention`, con el plazo y el corte que se le digan.
 */
function acuseDePurgaAsiento(int $site, string $corte, int $anos, string $scope = 'work_records'): int
{
    $entry = resolve(RecordAuditEntry::class)->handle(new RecordAuditEntryCommand(
        actor: AuditActor::system(),
        action: AuditAction::RetentionPurgeExecuted,
        subject: AuditSubject::of('retention'),
        payload: AuditPayload::of([
            'scope' => $scope,
            'cutoff_date' => $corte,
            'retention_years' => $anos,
            'site_id' => $site,
            'rows' => 0,
        ]),
    ));

    return (int) $entry->id;
}

/**
 * @param  array<string, string|bool>  $opciones
 * @return array{code: int, output: string}
 */
function acuseDePurgaOrden(array $opciones): array
{
    $code = Artisan::call('compliance:reconcile-work-record', $opciones);

    return ['code' => $code, 'output' => Artisan::output()];
}

function acuseDePurgaRevisiones(): int
{
    return DB::table('audit_log')->where('action', 'retention.purge_reviewed')->count();
}

it('sin motivo ni responsable enseña la vista previa, sale con 2 y no escribe nada', function (): void {
    $purga = acuseDePurgaAsiento(WorkforceFixtures::site(), '2024-10-08', 3);
    $asientos = DB::table('audit_log')->count();

    $resultado = acuseDePurgaOrden(['--acknowledge' => (string) $purga]);

    expect($resultado['code'])->toBe(2)
        ->and($resultado['output'])->toContain('retention.purge_executed #'.$purga.': retention_years')
        ->and($resultado['output'])->toContain('vuelve a explicar los borrados de jornadas anteriores a 2024-10-08')
        ->and($resultado['output'])->toContain('faltan --reason y --responsible')
        ->and(DB::table('audit_log')->count())->toBe($asientos);
})->group('RL-04', 'RS-07');

it('no se combina con una pasada de conciliacion', function (array $extra): void {
    $purga = acuseDePurgaAsiento(WorkforceFixtures::site(), '2024-10-08', 3);

    $resultado = acuseDePurgaOrden([
        '--acknowledge' => (string) $purga,
        '--reason' => RECONCILE_ACKNOWLEDGE_MOTIVO,
        '--responsible' => (string) ManagementUsers::withRole(UserRole::ADMIN)->id,
        ...$extra,
    ]);

    expect($resultado['code'])->toBe(2)
        ->and($resultado['output'])->toContain('o se concilia o se revisa')
        ->and(acuseDePurgaRevisiones())->toBe(0);
})->with([
    'con --full' => [['--full' => true]],
    'con --days' => [['--days' => '3']],
])->group('RL-04', 'RS-07');

it('rechaza con 1 y sin escribir lo que no se puede revisar', function (string $caso, string $mensaje): void {
    $site = WorkforceFixtures::site();
    $responsable = ManagementUsers::withRole(UserRole::ADMIN);

    $purga = match ($caso) {
        // Una purga, pero del log tecnico: no es del registro horario.
        'otro asiento' => acuseDePurgaAsiento($site, '2024-10-08', 3, 'technical_log'),
        // Cuatro años y el corte que permiten: la conciliacion ya la admite.
        'admisible' => acuseDePurgaAsiento($site, '2023-10-07', 4),
        default => acuseDePurgaAsiento($site, '2024-10-08', 3),
    };

    if ($caso === 'responsable inactivo') {
        DB::table('users')->where('id', $responsable->id)->update(['is_active' => false]);
    }

    $resultado = acuseDePurgaOrden([
        '--acknowledge' => (string) $purga,
        '--reason' => $caso === 'motivo corto' ? 'porque si' : RECONCILE_ACKNOWLEDGE_MOTIVO,
        '--responsible' => (string) $responsable->id,
    ]);

    expect($resultado['code'])->toBe(1, $resultado['output'])
        ->and($resultado['output'])->toContain($mensaje)
        ->and(acuseDePurgaRevisiones())->toBe(0);
})->with([
    'otro asiento' => ['otro asiento', 'no es una purga del registro horario'],
    'purga admisible' => ['admisible', 'es una purga admisible'],
    'responsable inactivo' => ['responsable inactivo', 'no es una cuenta de gestión activa'],
    'motivo corto' => ['motivo corto', 'al menos 10 caracteres'],
])->group('RL-04', 'RS-07');

it('escribe un unico asiento de revision con el payload del diseño, y no repite', function (): void {
    $site = WorkforceFixtures::site();
    $purga = acuseDePurgaAsiento($site, '2024-10-08', 3);
    // RRHH firma igual que admin: lo que se exige es una persona que responda
    // (decision D7), no un rol.
    $responsable = ManagementUsers::withRole(UserRole::RRHH);

    $resultado = acuseDePurgaOrden([
        '--acknowledge' => (string) $purga,
        '--reason' => '  '.RECONCILE_ACKNOWLEDGE_MOTIVO.'  ',
        '--responsible' => (string) $responsable->id,
    ]);

    expect($resultado['code'])->toBe(0, $resultado['output'])
        ->and($resultado['output'])->toContain('la purga '.$purga.' queda revisada');

    /** @var object{id: int, actor_type: string, actor_id: int|null, subject_type: string|null, payload: string} $revision */
    $revision = DB::table('audit_log')->where('action', 'retention.purge_reviewed')->sole();

    /** @var array<string, mixed> $payload */
    $payload = json_decode($revision->payload, true, 512, JSON_THROW_ON_ERROR);
    ksort($payload, SORT_STRING);

    expect($revision->actor_type)->toBe('user')
        ->and($revision->actor_id)->toBe($responsable->id)
        ->and($revision->subject_type)->toBe('retention')
        ->and($revision->id)->toBeGreaterThan($purga)
        ->and($payload)->toBe([
            'channel' => 'console',
            'cutoff_date' => '2024-10-08',
            'explains_deletions' => true,
            // El motivo, recortado.
            'reason' => RECONCILE_ACKNOWLEDGE_MOTIVO,
            'responsible_user_id' => $responsable->id,
            'retention_floor_years' => 4,
            'retention_years' => 3,
            'reviewed_action' => 'retention.purge_executed',
            'reviewed_audit_entry_id' => $purga,
            'site_id' => $site,
            'violations' => ['retention_years'],
        ])
        // La purga sigue donde estaba.
        ->and(DB::table('audit_log')->where('id', $purga)->value('action'))->toBe('retention.purge_executed');

    // Una revision no se repite ni se corrige.
    $otra = acuseDePurgaOrden([
        '--acknowledge' => (string) $purga,
        '--reason' => RECONCILE_ACKNOWLEDGE_MOTIVO,
        '--responsible' => (string) $responsable->id,
    ]);

    expect($otra['code'])->toBe(1)
        ->and($otra['output'])->toContain('ya está revisado por el asiento '.$revision->id)
        ->and(acuseDePurgaRevisiones())->toBe(1)
        ->and(Artisan::call('compliance:verify-audit-chain'))->toBe(0, Artisan::output());
})->group('RL-04', 'RS-07', 'RL-02');

it('habla ingles en una instalacion en ingles', function (): void {
    app()->setLocale('en');
    $purga = acuseDePurgaAsiento(WorkforceFixtures::site(), '2024-10-08', 3);

    $resultado = acuseDePurgaOrden(['--acknowledge' => (string) $purga]);

    expect($resultado['code'])->toBe(2)
        ->and($resultado['output'])->toContain('It also explains again deletions of work days before 2024-10-08')
        ->and($resultado['output'])->toContain('To mark entry '.$purga.' as reviewed, --reason and --responsible are required. Nothing was written.')
        ->and(acuseDePurgaRevisiones())->toBe(0);
})->group('RL-04', 'RS-07');
