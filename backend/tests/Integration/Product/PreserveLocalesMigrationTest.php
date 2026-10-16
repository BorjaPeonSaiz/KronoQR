<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;

/*
 * La migracion que deja `["es","en"]` en las instalaciones que ya estaban en uso
 * (**RF-KI-05**, bloque 26 de la 2.2.1). Desde esta version el valor de serie de
 * `LOCALE_AVAILABLE` es `["es"]`, y una actualizacion no puede quitarle el ingles
 * a quien ya lo tenia sin que lo decida.
 *
 * Como `AuditorAbilitiesMigrationTest`: con el rol de migracion y dentro de una
 * transaccion que se deshace siempre.
 */

uses(RefreshDatabase::class);

const PRESERVE_LOCALES_MIGRATION_FILE = '2026_10_16_100400_preserve_locales_on_existing_installations.php';

function preserveLocalesRun(Closure $prueba): void
{
    $previa = DB::getDefaultConnection();
    DB::setDefaultConnection(config()->string('database.migrations.connection'));
    DB::beginTransaction();

    try {
        // Punto de partida de una instalacion que nunca toco los idiomas.
        DB::table('installation_settings')->where('key', 'LOCALE_AVAILABLE')->delete();
        DB::table('setup_progress')->delete();

        /** @var Migration $migration */
        $migration = require database_path('migrations/'.PRESERVE_LOCALES_MIGRATION_FILE);
        $prueba($migration);
    } finally {
        DB::rollBack();
        DB::setDefaultConnection($previa);
    }
}

function preserveLocalesInUse(): void
{
    DB::table('setup_progress')->insert([
        'step' => 'completion',
        'state' => 'completed',
        'recorded_at' => '2026-09-01 08:00:00+00',
    ]);
}

/** @return list<string>|null */
function preserveLocalesStored(): ?array
{
    $value = DB::table('installation_settings')->where('key', 'LOCALE_AVAILABLE')->value('value');

    if (! \is_string($value)) {
        return null;
    }

    /** @var list<string> $decoded */
    $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

it('una instalacion en uso conserva el español y el ingles, y down() lo deshace', function (): void {
    preserveLocalesRun(function (Migration $migration): void {
        \assert(method_exists($migration, 'down') && method_exists($migration, 'up'));
        preserveLocalesInUse();

        $migration->up();
        $migration->up();

        expect(preserveLocalesStored())->toBe(['es', 'en'])
            ->and(DB::table('installation_settings')->where('key', 'LOCALE_AVAILABLE')->count())->toBe(1);

        $migration->down();
        $migration->down();

        expect(preserveLocalesStored())->toBeNull();
    });
})->group('RF-KI-05', 'RF-PD-01');

it('basta con que haya cuentas, aunque el asistente no haya dejado rastro', function (): void {
    preserveLocalesRun(function (Migration $migration): void {
        \assert(method_exists($migration, 'up'));
        ManagementUsers::withRole(UserRole::ADMIN);

        $migration->up();

        expect(preserveLocalesStored())->toBe(['es', 'en']);
    });
})->group('RF-KI-05');

it('una instalacion nueva no recibe fila: rige el español de serie', function (): void {
    preserveLocalesRun(function (Migration $migration): void {
        \assert(method_exists($migration, 'up'));
        DB::table('users')->delete();

        $migration->up();

        expect(preserveLocalesStored())->toBeNull();
    });
})->group('RF-KI-05');

it('no toca la eleccion que el cliente ya habia guardado', function (): void {
    preserveLocalesRun(function (Migration $migration): void {
        \assert(method_exists($migration, 'down') && method_exists($migration, 'up'));
        preserveLocalesInUse();
        DB::table('installation_settings')->insert([
            'key' => 'LOCALE_AVAILABLE',
            'value' => '["en"]',
            'updated_at' => '2026-09-02 08:00:00+00',
        ]);

        $migration->up();
        expect(preserveLocalesStored())->toBe(['en']);

        $migration->down();
        expect(preserveLocalesStored())->toBe(['en']);
    });
})->group('RF-KI-05');

it('al revertir, no borra la fila si el cliente la ha guardado despues desde el panel', function (): void {
    preserveLocalesRun(function (Migration $migration): void {
        \assert(method_exists($migration, 'down') && method_exists($migration, 'up'));
        preserveLocalesInUse();

        $migration->up();

        // Guardar desde el panel deja otro instante, aunque sea con el mismo valor.
        DB::table('installation_settings')
            ->where('key', 'LOCALE_AVAILABLE')
            ->update(['updated_at' => '2026-10-20 09:00:00+00']);

        $migration->down();

        expect(preserveLocalesStored())->toBe(['es', 'en']);
    });
})->group('RF-KI-05');
