<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El historico sale por las dos puertas por las que salen datos de una
 * instalacion, y **cada una lleva lo que le toca** (RF-PD-15, decision 12 de la
 * ficha 5.12).
 *
 * ## Las dos puertas y por que llevan cosas distintas
 *
 * - **El paquete de diagnostico** va hacia el FABRICANTE (ADR-020, regla dura
 *   16), asi que cada grupo pasa por la lista de permitidos y **el autor de la
 *   resolucion no viaja**: es un nombre de una cuenta de gestion del hotel y no
 *   ayuda a diagnosticar nada.
 * - **La exportacion integra** se queda con el CLIENTE, que es su responsable
 *   del tratamiento (RL-16, RL-20), asi que lleva la tabla entera, autor
 *   incluido.
 *
 * Mismo mecanismo —`FieldAllowlist`—, politicas opuestas. Esa es la decision, y
 * estas pruebas son las que impiden que se confundan.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Clock::class, FixedClock::at('2026-09-09 08:00:00'));

    WorkforceFixtures::site();
    LicenseKeys::install();
});

/**
 * Un grupo con lo peor que podria llevar dentro **si el saneado no existiera**.
 *
 * Aqui se escribe a mano, sin pasar por el sumidero, a proposito: lo que se
 * comprueba es que el paquete no saca de la tabla nada que no deba, y para eso
 * hay que poder poner en la tabla algo que no deba salir.
 */
function grupoParaElPaquete(?int $resueltoPor = null): int
{
    $ahora = now()->toDateTimeString('microsecond');

    return (int) DB::table('error_events')->insertGetId([
        'fingerprint' => bin2hex(random_bytes(32)),
        'level' => 'critical',
        'source' => 'scheduler',
        'module' => 'compliance',
        'code' => null,
        'message' => 'la reconciliacion nocturna fallo',
        'exception_class' => 'RuntimeException',
        'file' => 'app/Modules/Compliance/Application/UseCase/DetectIncidents.php',
        'line' => 142,
        'context' => json_encode(['command' => 'compliance:detect-incidents']),
        'trace_id' => str_repeat('a1b2c3d4', 4),
        'device_id' => '0199f0aa-1111-7000-8000-0123456789ab',
        'employee_uuid' => '0199f0aa-2222-7000-8000-0123456789ab',
        'app_version' => '2.2.0',
        'occurrences' => 12,
        'first_seen_at' => $ahora,
        'last_seen_at' => $ahora,
        'resolved_at' => $resueltoPor === null ? null : $ahora,
        'resolved_by_user_id' => $resueltoPor,
        'created_at' => $ahora,
        'updated_at' => $ahora,
    ]);
}

it('el paquete de diagnostico lleva el historico real, con resumen, grupos y trace_id', function (): void {
    grupoParaElPaquete();

    $respuesta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/diagnostics/bundle')
        ->assertOk();

    /**
     * @var array{
     *     status: string,
     *     period_days: int,
     *     summary: array{open: int, resolved: int, by_source: array<string, mixed>, by_level: array<string, mixed>},
     *     total_groups: int,
     *     truncated: bool,
     *     groups: list<array<string, mixed>>
     * } $seccion
     */
    $seccion = $respuesta->json('error_events');

    expect($seccion['status'])->toBe('ok')
        // Y ya NO dice `not_installed`: eso afirmaba que no ha habido errores, y
        // soporte descartaba la hipotesis correcta.
        ->and($seccion)->not->toHaveKey('requirement')
        ->and($seccion['period_days'])->toBe(7)
        ->and($seccion['summary']['open'])->toBe(1)
        ->and($seccion['summary']['resolved'])->toBe(0)
        // Todas las claves siempre, tambien las que estan a cero: un paquete al
        // que le falta `worker` no dice «no hubo errores de la cola».
        ->and(array_keys($seccion['summary']['by_source']))
        ->toBe(['api', 'worker', 'scheduler', 'console', 'kiosk', 'admin', 'portal'])
        ->and($seccion['summary']['by_source']['scheduler'])->toBe(['open' => 1, 'resolved' => 0])
        ->and($seccion['summary']['by_level']['critical'])->toBe(['open' => 1, 'resolved' => 0])
        ->and($seccion['total_groups'])->toBe(1)
        ->and($seccion['truncated'])->toBeFalse()
        ->and($seccion['groups'])->toHaveCount(1)
        // El `trace_id` va con todas las letras: es lo que permite correlacionar
        // con el log tecnico DEL CLIENTE cuando el cliente lo conserva.
        ->and($seccion['groups'][0]['trace_id'])->toBe(str_repeat('a1b2c3d4', 4))
        ->and($seccion['groups'][0]['occurrences'])->toBe(12);
})->group('RF-PD-15', 'RF-PD-09');

it('el paquete NO saca el nombre de quien resolvio un error', function (): void {
    // La unica columna del historico que lleva un nombre de persona, y no esta
    // en la lista de permitidos del recolector: no ayuda a diagnosticar nada y
    // es informacion de la organizacion del cliente (ADR-020, regla dura 16).
    $usuario = ManagementUsers::withRole(UserRole::ADMIN);
    grupoParaElPaquete($usuario->id);

    $respuesta = Api::as(ManagementUsers::tokenFor($usuario))
        ->post('/api/v1/diagnostics/bundle')
        ->assertOk();

    $seccion = json_encode($respuesta->json('error_events'), JSON_UNESCAPED_UNICODE);

    expect($seccion)->not->toContain($usuario->name)
        ->and($seccion)->not->toContain($usuario->email)
        ->and($seccion)->not->toContain('resolved_by_user_id')
        // Y el instante de la resolucion SI sale: dice que alguien lo atendio,
        // que es lo que soporte necesita saber, sin decir quien.
        ->and($respuesta->json('error_events.groups.0.resolved_at'))->toBeString()
        ->and($respuesta->json('error_events.summary.resolved'))->toBe(1);
})->group('RF-PD-15', 'RL-19');

it('la seccion sale igual con el paquete anonimizado y con datos personales', function (): void {
    // Aqui no hay datos personales que incluir ni que omitir: la seccion es la
    // misma en los dos paquetes.
    grupoParaElPaquete();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    $anonimo = Api::as($token)->post('/api/v1/diagnostics/bundle')->assertOk();
    $completo = Api::as($token)
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true, 'period_days' => 7])
        ->assertOk();

    expect($completo->json('error_events.groups'))->toBe($anonimo->json('error_events.groups'));
})->group('RF-PD-15', 'RL-19');

it('la exportacion integra escribe error_events.csv con la tabla entera', function (): void {
    // Aqui SI sale el autor de la resolucion: son datos del cliente y el cliente
    // es su responsable del tratamiento (RL-20).
    DataExports::useTemporaryPath();

    $usuario = ManagementUsers::withRole(UserRole::ADMIN);
    grupoParaElPaquete($usuario->id);

    [$codigo, $salida] = Commands::run('product:export-all');

    expect($codigo)->toBe(0, $salida);

    $fila = DB::table('data_exports')->latest('id')->first();

    /** @var array<string, int> $recuentos */
    $recuentos = json_decode((string) $fila?->row_counts, true, 512, JSON_THROW_ON_ERROR);

    expect($recuentos)->toHaveKey('error_events')
        ->and($recuentos['error_events'])->toBe(1);

    $zip = new ZipArchive;

    expect($zip->open((string) $fila?->file_path))->toBeTrue();

    $csv = (string) $zip->getFromName('error_events.csv');

    $zip->close();

    expect($csv)->toContain('fingerprint')
        ->and($csv)->toContain('resolved_by_user_uuid')
        ->and($csv)->toContain('la reconciliacion nocturna fallo')
        ->and($csv)->toContain((string) $usuario->uuid)
        // Ni un identificador interno (doc 01 §5.5): la referencia a `users` va
        // por uuid, como en el resto de la exportacion.
        ->and($csv)->not->toContain('resolved_by_user_id');
})->group('RF-PD-15', 'RF-PD-14', 'RL-20');
