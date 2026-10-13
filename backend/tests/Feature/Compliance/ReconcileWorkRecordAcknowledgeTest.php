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
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Observability\CapturedLog;
use Tests\Support\Telemetry\RecordingTracer;
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

it('sale con 2 y sin escribir si un id no es un entero positivo escrito tal cual', function (string $opcion, string $valor, string $mensaje): void {
    $purga = acuseDePurgaAsiento(WorkforceFixtures::site(), '2024-10-08', 3);
    $asientos = DB::table('audit_log')->count();

    $resultado = acuseDePurgaOrden([
        '--acknowledge' => (string) $purga,
        '--reason' => RECONCILE_ACKNOWLEDGE_MOTIVO,
        '--responsible' => (string) ManagementUsers::withRole(UserRole::ADMIN)->id,
        // `(int)` habria leido `12abc` como 12 y `7x` como 7.
        $opcion => $valor,
    ]);

    expect($resultado['code'])->toBe(2, $resultado['output'])
        ->and($resultado['output'])->toContain($mensaje)
        // Ni siquiera la vista previa: no se ha leido nada.
        ->and($resultado['output'])->not->toContain('retention.purge_executed #')
        ->and(DB::table('audit_log')->count())->toBe($asientos);
})->with([
    'asiento con letras detras' => ['--acknowledge', '12abc', 'tiene que ser el id del asiento, un entero positivo'],
    'asiento negativo' => ['--acknowledge', '-3', 'tiene que ser el id del asiento, un entero positivo'],
    'asiento cero' => ['--acknowledge', '0', 'tiene que ser el id del asiento, un entero positivo'],
    'responsable con letras detras' => ['--responsible', '7x', 'tiene que ser el id de la cuenta, un entero positivo'],
    'responsable cero' => ['--responsible', '0', 'tiene que ser el id de la cuenta, un entero positivo'],
    'responsable negativo' => ['--responsible', '-7', 'tiene que ser el id de la cuenta, un entero positivo'],
])->group('RL-04', 'RS-07');

it('dice en ingles que el id no es valido', function (): void {
    app()->setLocale('en');

    $resultado = acuseDePurgaOrden(['--acknowledge' => '12abc']);

    expect($resultado['code'])->toBe(2)
        ->and($resultado['output'])->toContain('--acknowledge must be the audit entry id, a positive integer; "12abc" is not. Nothing was written.')
        ->and(acuseDePurgaRevisiones())->toBe(0);
})->group('RL-04', 'RS-07');

it('no deja el motivo de la revision en el log tecnico ni en las trazas', function (): void {
    // Texto libre: puede llevar un nombre (regla dura 21). Va al asiento y a
    // ningun otro sitio. Se busca en el log ya procesado por Monolog y en todos
    // los spans cerrados —nombre, atributos y eventos—, incluidos los de la base
    // de datos, que llevarian el motivo si se trazaran los valores enlazados.
    $centinela = 'Centinela-Motivo-Q7Zr: lo reviso la persona que firma.';
    $purga = acuseDePurgaAsiento(WorkforceFixtures::site(), '2024-10-08', 3);
    $responsable = ManagementUsers::withRole(UserRole::ADMIN)->id;

    RecordingTracer::around(function (RecordingTracer $tracer) use ($centinela, $purga, $responsable): void {
        CapturedLog::around(function (CapturedLog $log) use ($centinela, $purga, $responsable, $tracer): void {
            $resultado = acuseDePurgaOrden([
                '--acknowledge' => (string) $purga,
                '--reason' => $centinela,
                '--responsible' => (string) $responsable,
            ]);

            expect($resultado['code'])->toBe(0, $resultado['output'])
                // El asiento si lo lleva: la prueba no pasa por no escribirlo.
                ->and(DB::table('audit_log')->where('action', 'retention.purge_reviewed')->sole()->payload)->toContain('Centinela-Motivo-Q7Zr');

            $spans = $tracer->finishedSpans();
            $volcado = json_encode(array_map(static fn (ImmutableSpan $span): array => [
                'name' => $span->getName(),
                'attributes' => $span->getAttributes()->toArray(),
                'events' => array_map(
                    static fn (EventInterface $event): array => [$event->getName(), $event->getAttributes()->toArray()],
                    $span->getEvents(),
                ),
            ], $spans), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            // Que se ha escrito algo en los dos sitios: si no, no prueba nada.
            expect($log->dump())->toContain('work_record_purge_reviewed')
                ->and(array_map(static fn (ImmutableSpan $span): string => $span->getName(), $spans))
                ->toContain('compliance.review_out_of_bounds_purge')
                ->and($log->dump())->not->toContain('Centinela-Motivo-Q7Zr')
                ->and($volcado)->not->toContain('Centinela-Motivo-Q7Zr');
        });
    });
})->group('RF-PD-15', 'RL-04', 'RS-07');
