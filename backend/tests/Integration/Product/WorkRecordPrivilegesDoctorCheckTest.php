<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\DatabaseProbe;
use App\Modules\Product\Infrastructure\Diagnostics\ServiceInspector;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;

/*
 * `product:doctor` vigila entre actualizaciones los privilegios que ADR-057 §1 y
 * §7 retiran al rol de la aplicacion (bloque 6 de la 2.2.1; RF-PD-13, RL-04,
 * RS-07).
 *
 * `update.sh` los comprueba y repara al actualizar, pero volver a ejecutar la
 * provision o un `GRANT` a mano los devuelve en silencio hasta la siguiente
 * actualizacion. Contra PostgreSQL de verdad y con `fichaje_app` como conexion
 * por defecto: con el propietario, la sonda daria siempre «de mas».
 *
 * Los `GRANT` de la prueba los hace el rol de migracion y se CONFIRMAN, porque
 * la sonda los mira desde otra sesion; el `finally` los retira. Son altas
 * puras (ninguno pisa un privilegio de columna legitimo), asi que el `REVOKE`
 * simetrico deja el catalogo exactamente como estaba.
 */

uses(RefreshDatabase::class);

/**
 * @return list<DoctorFinding>
 */
function privilegiosRegistroDoctor(?ConnectionInterface $connection = null): array
{
    $inspector = new ServiceInspector(
        $connection ?? DB::connection(),
        resolve(Redis::class),
        database_path('migrations'),
        'sync',
        'default',
        false,
        'null',
    );

    return array_values(array_filter(
        new DatabaseProbe($inspector)->run(),
        static fn (DoctorFinding $finding): bool => $finding->id === 'database.work_record_privileges',
    ));
}

function privilegiosRegistroMigrador(): ConnectionInterface
{
    return DB::connection(config()->string('database.migrations.connection'));
}

it('corre con el rol de la aplicacion, que es el que vigila la sonda', function (): void {
    /** @var object{name: string} $row */
    $row = DB::selectOne('SELECT current_user AS name');

    expect($row->name)->toBe(Config::string('database.roles.application'));
})->group('RF-PD-13', 'RS-07');

it('da correcto con los privilegios que dejan las migraciones del bloque 6', function (): void {
    $findings = privilegiosRegistroDoctor();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Ok)
        ->and($findings[0]->details['excess'])->toBe([])
        ->and($findings[0]->details['database_user'])->toBe(Config::string('database.roles.application'));
})->group('RF-PD-13', 'RS-07', 'RL-04');

it('avisa, con lo que sobra, si el rol recupera UPDATE de tabla o las columnas del suelo legal', function (): void {
    $migrator = privilegiosRegistroMigrador();
    $application = '"'.Config::string('database.roles.application').'"';

    $grants = [
        'UPDATE ON TABLE scan_events',
        'UPDATE ON TABLE shift_corrections',
        'UPDATE (jurisdiction) ON TABLE compliance_profiles',
        'INSERT (is_default) ON TABLE compliance_profiles',
        'INSERT (compliance_profile_id) ON TABLE sites',
    ];

    try {
        foreach ($grants as $grant) {
            $migrator->statement('GRANT '.$grant.' TO '.$application);
        }

        $findings = privilegiosRegistroDoctor();
    } finally {
        foreach ($grants as $grant) {
            $migrator->statement('REVOKE '.$grant.' FROM '.$application);
        }
    }

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Warning)
        ->and($findings[0]->variant)->toBe('excess')
        ->and($findings[0]->details['excess'])->toBe([
            'compliance_profiles.is_default:INSERT',
            'compliance_profiles.jurisdiction:UPDATE',
            'scan_events:UPDATE',
            'shift_corrections:UPDATE',
            'sites.compliance_profile_id:INSERT',
        ])
        ->and($findings[0]->params['user'])->toBe(Config::string('database.roles.application'));

    // Y el catalogo queda como estaba: la siguiente pasada vuelve a dar correcto.
    expect(privilegiosRegistroDoctor()[0]->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-13', 'RS-07', 'RL-04');

it('el aviso y su que hacer estan en español y en ingles', function (string $locale): void {
    $finding = DoctorFinding::warning(
        'database.work_record_privileges',
        'excess',
        params: ['user' => 'fichaje_app', 'excess' => 'scan_events:UPDATE'],
    );

    $unknown = DoctorFinding::warning('database.work_record_privileges', 'unknown');
    $texto = static function (string $key, array $params) use ($locale): string {
        $text = trans($key, $params, $locale);

        expect($text)->toBeString();
        \assert(is_string($text));

        // Una clave sin traducir vuelve tal cual: `doctor.checks.…`.
        expect($text)->not->toStartWith('doctor.');

        return $text;
    };

    expect($texto($finding->messageKey(), $finding->params))->toContain('fichaje_app')->toContain('scan_events:UPDATE');

    $texto((string) $finding->fixKey(), $finding->params);
    $texto(DoctorFinding::ok('database.work_record_privileges')->messageKey(), []);
    $texto($unknown->messageKey(), []);
    $texto((string) $unknown->fixKey(), []);
})->with(['es', 'en'])->group('RF-PD-13');

it('no aparece mientras las migraciones que retiran esos privilegios no esten aplicadas', function (): void {
    // Con el esquema anterior, esos privilegios son los que la version
    // anterior necesita, y `database.migrations_pending` ya lo dice. Se borra
    // la fila de `migrations` dentro de una transaccion del rol de migracion
    // que se deshace, y la sonda lee por esa misma sesion.
    $migrator = privilegiosRegistroMigrador();
    $migrator->beginTransaction();

    try {
        $migrator->table('migrations')
            ->where('migration', ServiceInspector::WORK_RECORD_PRIVILEGE_MIGRATIONS[0])
            ->delete();

        $findings = privilegiosRegistroDoctor($migrator);
    } finally {
        $migrator->rollBack();
    }

    expect($findings)->toBe([]);
})->group('RF-PD-13', 'RS-07');

// --- Las guardas del bloque 7 (ADR-057 §2 y §3) ----------------------------------

it('con la migracion del bloque 7, da correcto y sin incidencias en las guardas', function (): void {
    $findings = privilegiosRegistroDoctor();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Ok)
        ->and($findings[0]->details['guards_expected'])->toBeTrue()
        ->and($findings[0]->details['guard_issues'])->toBe([]);
})->group('RF-PD-13', 'RS-07', 'RL-04');

it('avisa si falta o esta desactivada una guarda, o la funcion de purga pierde su forma', function (string $statement, array $expected): void {
    // En una transaccion del rol de migracion que se deshace, y la sonda lee
    // por esa misma sesion: nada de esto llega a confirmarse. Con el
    // propietario, lo que sobra sale tambien; aqui se mira la variante y las
    // guardas.
    $migrator = privilegiosRegistroMigrador();
    $migrator->beginTransaction();

    try {
        $migrator->statement($statement);

        $findings = privilegiosRegistroDoctor($migrator);
    } finally {
        $migrator->rollBack();
    }

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Warning)
        ->and($findings[0]->variant)->toBe('guards')
        ->and($findings[0]->details['guard_issues'])->toBe($expected)
        ->and($findings[0]->params['issues'])->toContain($expected[0]);

    // Deshecho: la siguiente pasada, con la aplicacion, vuelve a dar correcto.
    expect(privilegiosRegistroDoctor()[0]->status)->toBe(DoctorStatus::Ok);
})->with([
    'un trigger desactivado' => [
        'ALTER TABLE incidents DISABLE TRIGGER incidents_guard_insert',
        ['disabled_trigger:incidents_guard_insert'],
    ],
    'todos los de una tabla desactivados' => [
        'ALTER TABLE shift_entries DISABLE TRIGGER ALL',
        [
            // El de `closed_by` (2.3.0) no es de la lista de guardas, pero un
            // trigger desactivado en el registro se avisa igual.
            'disabled_trigger:shift_entries_closed_by_default',
            'disabled_trigger:shift_entries_guard_insert',
            'disabled_trigger:shift_entries_guard_transition',
            'disabled_trigger:shift_entries_superseded_is_linked',
        ],
    ],
    'solo en replica' => [
        'ALTER TABLE shift_entries ENABLE REPLICA TRIGGER shift_entries_guard_transition',
        ['disabled_trigger:shift_entries_guard_transition'],
    ],
    'un trigger borrado' => [
        'DROP TRIGGER shift_entries_superseded_is_linked ON shift_entries',
        ['missing_trigger:shift_entries_superseded_is_linked'],
    ],
    'la purga sin SECURITY DEFINER' => [
        'ALTER FUNCTION public.work_record_purge_batch(date, integer) SECURITY INVOKER',
        ['purge_function:not_security_definer'],
    ],
    'la purga sin search_path' => [
        'ALTER FUNCTION public.work_record_purge_batch(date, integer) RESET search_path',
        ['purge_function:no_search_path'],
    ],
    'la purga borrada' => [
        'DROP FUNCTION public.work_record_purge_batch(date, integer)',
        ['purge_function:missing'],
    ],
])->group('RF-PD-13', 'RS-07', 'RL-04');

// --- La zona del centro, fija tambien en la base (RN-23, bloque 2 de la 2.3.0) ---

it('avisa si falta o esta desactivada la guarda de la zona, o su funcion pierde la forma', function (string $statement, array $expected): void {
    $migrator = privilegiosRegistroMigrador();
    $migrator->beginTransaction();

    try {
        // `:app`, el rol de la aplicacion: el conjunto de datos se lee antes
        // de que arranque la configuracion.
        $migrator->statement(str_replace(':app', '"'.Config::string('database.roles.application').'"', $statement));

        $findings = privilegiosRegistroDoctor($migrator);
    } finally {
        $migrator->rollBack();
    }

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Warning)
        ->and($findings[0]->variant)->toBe('guards')
        ->and($findings[0]->details['guard_issues'])->toBe($expected);

    expect(privilegiosRegistroDoctor()[0]->status)->toBe(DoctorStatus::Ok);
})->with([
    'el trigger de sites desactivado' => [
        'ALTER TABLE sites DISABLE TRIGGER zz_sites_guard_timezone',
        ['disabled_trigger:zz_sites_guard_timezone'],
    ],
    'el trigger de sites borrado' => [
        'DROP TRIGGER zz_sites_guard_timezone ON sites',
        ['missing_trigger:zz_sites_guard_timezone'],
    ],
    'la correccion sin SECURITY DEFINER' => [
        'ALTER FUNCTION public.site_correct_timezone(bigint, text, text) SECURITY INVOKER',
        ['site_timezone_function:not_security_definer'],
    ],
    'la correccion sin search_path' => [
        'ALTER FUNCTION public.site_correct_timezone(bigint, text, text) RESET search_path',
        ['site_timezone_function:no_search_path'],
    ],
    'la correccion borrada' => [
        'DROP FUNCTION public.site_correct_timezone(bigint, text, text)',
        ['site_timezone_function:missing'],
    ],
    'la correccion para PUBLIC' => [
        'GRANT EXECUTE ON FUNCTION public.site_correct_timezone(bigint, text, text) TO PUBLIC',
        ['site_timezone_function:public_execute'],
    ],
    'la purga para PUBLIC' => [
        'GRANT EXECUTE ON FUNCTION public.work_record_purge_batch(date, integer) TO PUBLIC',
        ['purge_function:public_execute'],
    ],
    'la traza borrada' => [
        'DROP TABLE site_timezone_changes',
        ['site_timezone_trace:missing'],
    ],
    'la linea base borrada' => [
        'DROP TABLE site_timezone_audit_baseline',
        ['site_timezone_baseline:missing'],
    ],
])->group('RF-PD-13', 'RS-07', 'RN-23');

it('avisa si la aplicacion puede escribir la traza de cambios de zona o usar su secuencia', function (): void {
    // Con el rol de la aplicacion, que es el que mira la sonda en produccion:
    // el propietario los tiene todos por serlo. Los GRANT se confirman y el
    // `finally` los retira, como en la prueba de lo que sobra.
    $migrator = privilegiosRegistroMigrador();
    $application = '"'.Config::string('database.roles.application').'"';
    $grants = [
        'INSERT, DELETE ON TABLE site_timezone_changes',
        'USAGE ON SEQUENCE site_timezone_changes_id_seq',
    ];

    try {
        foreach ($grants as $grant) {
            $migrator->statement('GRANT '.$grant.' TO '.$application);
        }

        $findings = privilegiosRegistroDoctor();
    } finally {
        foreach ($grants as $grant) {
            $migrator->statement('REVOKE '.$grant.' FROM '.$application);
        }
    }

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Warning)
        ->and($findings[0]->variant)->toBe('guards')
        ->and($findings[0]->details['guard_issues'])->toBe([
            'site_timezone_trace:DELETE',
            'site_timezone_trace:INSERT',
            'site_timezone_trace:sequence',
        ]);

    expect(privilegiosRegistroDoctor()[0]->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-13', 'RS-07', 'RN-23');

it('avisa si la aplicacion hereda del propietario de la traza en vez de omitir sus privilegios', function (): void {
    // Miembro del rol propietario: con un SET ROLE es el propietario (la
    // aplicacion es NOINHERIT). La sonda no lo puede dar por bueno.
    $migrator = privilegiosRegistroMigrador();
    $application = '"'.Config::string('database.roles.application').'"';
    $owner = '"'.Config::string('database.roles.migration').'"';

    try {
        $migrator->statement('GRANT '.$owner.' TO '.$application);

        $findings = privilegiosRegistroDoctor();
    } finally {
        $migrator->statement('REVOKE '.$owner.' FROM '.$application);
    }

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Warning)
        ->and($findings[0]->details['guard_issues'])
        ->toContain('site_timezone_trace:owner_membership', 'site_timezone_baseline:owner_membership');

    expect(privilegiosRegistroDoctor()[0]->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-13', 'RS-07', 'RN-23');

it('no mira la guarda de la zona mientras su migracion no este aplicada', function (): void {
    $migrator = privilegiosRegistroMigrador();
    $migrator->beginTransaction();

    try {
        $migrator->table('migrations')->where('migration', ServiceInspector::SITE_TIMEZONE_GUARD_MIGRATION)->delete();
        $migrator->statement('ALTER TABLE sites DISABLE TRIGGER zz_sites_guard_timezone');

        $details = privilegiosRegistroDoctor($migrator)[0]->details;
    } finally {
        $migrator->rollBack();
    }

    expect($details['guard_issues'])->toBe([]);
})->group('RF-PD-13', 'RN-23');

it('avisa si la aplicacion recupera DELETE sobre el registro', function (): void {
    $migrator = privilegiosRegistroMigrador();
    $application = '"'.Config::string('database.roles.application').'"';

    try {
        $migrator->statement('GRANT DELETE ON TABLE scan_events TO '.$application);

        $findings = privilegiosRegistroDoctor();
    } finally {
        $migrator->statement('REVOKE DELETE ON TABLE scan_events FROM '.$application);
    }

    expect($findings[0]->status)->toBe(DoctorStatus::Warning)
        ->and($findings[0]->variant)->toBe('excess')
        ->and($findings[0]->details['excess'])->toBe(['scan_events:DELETE']);
})->group('RF-PD-13', 'RS-07', 'RL-04');

it('no mira las guardas mientras la migracion del bloque 7 no este aplicada', function (): void {
    $migrator = privilegiosRegistroMigrador();
    $migrator->beginTransaction();

    try {
        $migrator->table('migrations')->where('migration', ServiceInspector::WORK_RECORD_GUARD_MIGRATION)->delete();
        $migrator->statement('ALTER TABLE incidents DISABLE TRIGGER incidents_guard_insert');

        $details = privilegiosRegistroDoctor($migrator)[0]->details;
    } finally {
        $migrator->rollBack();
    }

    expect($details['guards_expected'])->toBeFalse()
        ->and($details['guard_issues'])->toBe([]);
})->group('RF-PD-13', 'RS-07');

it('el aviso de las guardas y su que hacer estan en español y en ingles', function (string $locale): void {
    $finding = DoctorFinding::warning(
        'database.work_record_privileges',
        'guards',
        params: ['user' => 'fichaje_app', 'issues' => 'disabled_trigger:incidents_guard_insert'],
    );

    $message = trans($finding->messageKey(), $finding->params, $locale);
    $fix = trans((string) $finding->fixKey(), $finding->params, $locale);

    expect($message)->toBeString()->toContain('fichaje_app')->toContain('disabled_trigger:incidents_guard_insert')
        ->and($fix)->toBeString()->toContain('compliance:reconcile-work-record');

    // Una clave sin traducir vuelve tal cual: `doctor.checks.…`.
    expect($message)->not->toStartWith('doctor.');
    expect($fix)->not->toStartWith('doctor.');
})->with(['es', 'en'])->group('RF-PD-13');
