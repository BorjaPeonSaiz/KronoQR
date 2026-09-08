<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditableEvent;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\Event\DomainEvent;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El rastro que deja generar un paquete de diagnostico (regla dura 6, RL-04,
 * **RL-19**, ADR-020).
 *
 * ## Que se comprueba, y por que importa
 *
 * ADR-020 apoya toda su legitimidad en que **el cliente controla y ve** lo que
 * sale de su instalacion. Sin asiento, «lo controla» seria una intencion.
 *
 * Y sobre todo: que sean **dos acciones distintas** y no una con un booleano.
 * Es el punto entero de RL-19, y lo que permite responder «¿cuando han salido de
 * aqui datos de mi plantilla?» con un `WHERE action =` en lugar de recorriendo
 * cuatro años de paquetes generados.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));
});

function generarPaquete(DiagnosticsOptions $options): void
{
    app(GenerateDiagnosticsBundleHandler::class)->handle($options, DiagnosticsActor::Console);
}

/**
 * @return array<string, mixed>
 */
function asientoDe(string $action): array
{
    /** @var object{action: string, actor_type: string, subject_type: string|null, payload: string}|null $row */
    $row = DB::table('audit_log')->where('action', $action)->first();

    expect($row)->not->toBeNull('No hay ningun asiento `'.$action.'` en audit_log.');

    /** @var object{action: string, actor_type: string, subject_type: string|null, payload: string} $row */
    return [
        'actor_type' => $row->actor_type,
        'subject_type' => $row->subject_type,
        'payload' => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR),
    ];
}

it('generar el paquete anonimizado deja un asiento, y solo uno', function (): void {
    generarPaquete(DiagnosticsOptions::anonymized());

    $entry = asientoDe('diagnostics.bundle_generated');

    /** @var array<string, mixed> $payload */
    $payload = $entry['payload'];

    expect($entry['subject_type'])->toBe('diagnostics')
        ->and($payload['anonymized'])->toBeTrue()
        ->and($payload['generated_by'])->toBe('console')
        ->and($payload['sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($payload['size_bytes'])->toBeGreaterThan(0)
        ->and($payload['sections'])->toContain('doctor')
        // El contenido NO viaja: el asiento acaba en el trail y el trail se
        // exporta.
        ->and($payload)->not->toHaveKey('bundle');

    // Y **no** hay asiento de datos personales: no salieron.
    expect(DB::table('audit_log')->where('action', 'diagnostics.personal_data_included')->count())->toBe(0);
})->group('RF-PD-09', 'RL-04');

it('incluir datos personales deja ADEMAS un asiento propio', function (): void {
    // Los dos, no uno con un booleano. Son dos hechos que ocurrieron a la vez,
    // no dos versiones del mismo (RL-19).
    $siteId = WorkforceFixtures::onlySiteId();
    WorkforceFixtures::employee($siteId, firstName: 'Marta', lastName: 'Lopez');

    generarPaquete(DiagnosticsOptions::withPersonalData(14));

    expect(DB::table('audit_log')->where('action', 'diagnostics.bundle_generated')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'diagnostics.personal_data_included')->count())->toBe(1);

    /** @var array<string, mixed> $payload */
    $payload = asientoDe('diagnostics.personal_data_included')['payload'];

    expect($payload['period_days'])->toBe(14)
        ->and($payload['collections'])->toContain('employees')
        ->and($payload['collections'])->toContain('shift_entries')
        ->and($payload['collections'])->toContain('scan_events')
        ->and($payload['collections'])->toContain('incidents')
        // Ni un dato de nadie en el asiento que registra la salida de datos
        // personales, que seria absurdo (regla dura 21).
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Marta');

    // Y el de generacion dice que el paquete NO iba anonimizado, para que quien
    // lea el trail no tenga que cruzar las dos entradas para saberlo.
    /** @var array<string, mixed> $generation */
    $generation = asientoDe('diagnostics.bundle_generated')['payload'];

    expect($generation['anonymized'])->toBeFalse();
})->group('RF-PD-09', 'RL-19', 'RL-04');

it('los dos asientos son de la familia de acceso de soporte del bloque D', function (): void {
    // Comparten familia con las concesiones porque responden a la misma
    // pregunta: «¿que ha salido de esta instalacion hacia el fabricante?».
    // Separarlas obligaria a consultar dos veces para responderla.
    expect(AuditAction::DiagnosticsBundleGenerated->event())->toBe(AuditableEvent::SupportAccess)
        ->and(AuditAction::DiagnosticsPersonalDataIncluded->event())->toBe(AuditableEvent::SupportAccess);
})->group('RS-07', 'RL-19');

it('si el asiento no se puede escribir, el paquete no se entrega', function (): void {
    // La condicion que hace legitima la funcionalidad entera (ADR-027): el
    // asiento es sincrono y puede impedir la entrega. Un paquete que saliera sin
    // dejar rastro romperia la unica promesa de ADR-020.
    app()->bind(ProductEventPublisher::class, static fn (): ProductEventPublisher => new class implements ProductEventPublisher
    {
        public function publish(DomainEvent ...$events): void
        {
            throw new RuntimeException('la cadena de auditoria no acepta escrituras');
        }
    });

    expect(static fn () => generarPaquete(DiagnosticsOptions::anonymized()))
        ->toThrow(RuntimeException::class);
})->group('RF-PD-09', 'RL-04');

it('el rastro es visible para el cliente en su propio audit_log', function (): void {
    // RF-PD-11 lo exige para las concesiones y vale igual para el paquete: la
    // auditoria es del cliente, esta en su base de datos y no sale de ella.
    generarPaquete(DiagnosticsOptions::anonymized());

    $visible = DB::table('audit_log')
        ->where('subject_type', 'diagnostics')
        ->orderBy('id')
        ->get();

    expect($visible)->toHaveCount(1);
})->group('RF-PD-09', 'RL-04');
