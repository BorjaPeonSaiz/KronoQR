<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\Port\LicenseRepository;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Infrastructure\Persistence\DatabaseLicenseRepository;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\ReadOnlyLicenseConnection;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Una escritura de diagnostico que falla **no puede tumbar la pantalla desde la
 * que se diagnostica** (RF-PD-04, ADR-019, regla dura 15).
 *
 * ## El caso real que esto previene
 *
 * `last_verified_at` se escribe cuando alguien pregunta por el estado de su
 * licencia: `GET /api/v1/license` y `license:show`. Es decir, **es una escritura
 * en el camino de una lectura**, y de la lectura que hace precisamente quien
 * esta intentando averiguar por que le sale un aviso.
 *
 * Con la base de datos en solo lectura, el disco lleno o el rol sin permiso de
 * `UPDATE`, dejar subir esa excepcion daria un `500` con traza en el peor
 * momento posible. Lo que se pierde al tragarla es una marca de diagnostico; lo
 * que se salva es la unica pantalla que explica que esta pasando.
 *
 * El estado no se ve afectado: se recalcula siempre desde la clave firmada.
 *
 * ## Se sustituye la CONEXION, no el repositorio
 *
 * Lo que hay que comprobar es que el adaptador real no deja subir la excepcion.
 * Un repositorio postizo que lanzara probaria justo lo contrario: que el caso de
 * uso la propaga.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));

    app(ActivateLicenseHandler::class)->handle(
        new ActivateLicenseCommand(LicenseKeys::current()->issue()),
    );

    // A partir de aqui, anotar la verificacion falla siempre.
    app()->bind(
        LicenseRepository::class,
        static fn (): DatabaseLicenseRepository => new DatabaseLicenseRepository(
            ReadOnlyLicenseConnection::wrapping(DB::connection()),
            Log::channel(),
        ),
    );
});

it('GET /license responde 200 aunque no se pueda anotar la verificacion', function (): void {
    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/license')
        ->assertOk();

    // Y responde LA VERDAD, no un estado degradado por el fallo de escritura: el
    // estado sale de la clave firmada, no de esa columna.
    expect($response->json('data.state'))->toBe('valid')
        ->and($response->json('data.customer_name'))->toBe('Hotel de Pruebas, S.L.');
})->group('RF-PD-04', 'RF-PD-05');

it('license:show sale 0 aunque no se pueda anotar la verificacion', function (): void {
    $code = Artisan::call('license:show');
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('VIGENTE')
        // Ni rastro de la excepcion en lo que lee una persona.
        ->and($output)->not->toContain('read-only')
        ->and($output)->not->toContain('Exception');
})->group('RF-PD-04', 'RF-PD-05');
