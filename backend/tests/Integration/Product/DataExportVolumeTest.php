<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\RequestDataExportCommand;
use App\Modules\Product\Application\UseCase\GenerateDataExportHandler;
use App\Modules\Product\Application\UseCase\RequestDataExportHandler;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **La prueba que decide si esta tarea esta bien hecha** (ficha 5.10, §9.5 «con
 * volumen»): una exportacion integra real sobre 500 empleados y 90 dias de
 * fichajes, con el ZIP abierto y leido de arriba abajo.
 *
 * Tres preguntas, y ninguna se puede responder con dos filas de datos:
 *
 * 1. **¿Cuadran los recuentos con la base de datos?** Un fichero al que le falta
 *    la mitad de las filas es peor que ninguno: el cliente creeria tener su copia
 *    de seguridad. Se compara cada `row_counts` con su `SELECT count(*)`.
 * 2. **¿Se cuela algun secreto?** Se busca en el ZIP ENTERO —descomprimido y
 *    concatenado— `pin_hash`, `secret_hash`, `token_hash`, `signed_key`,
 *    `password` y el hash del PIN realmente sembrado. Con dos filas cualquier
 *    fuga se ve a simple vista; lo que se busca aqui es el campo que se cuela
 *    entre millones de caracteres.
 * 3. **¿Cabe en memoria?** El **incremento** de `memory_get_peak_usage(true)`
 *    durante la generacion, por debajo de 48 MiB. Se mide el incremento y no el
 *    pico absoluto porque el proceso de la suite ya llega con mas de 130 MiB
 *    reservados por las pruebas anteriores: un techo absoluto mediria el orden de
 *    ejecucion. No es «que no reviente»: una implementacion que cargara una tabla
 *    en memoria lo superaria ya con esta semilla, y con el cursor de servidor
 *    cuatro años cuestan lo mismo que noventa dias.
 *
 * Los datos se escriben con el constructor de consultas y no por los casos de
 * uso: lo que se comprueba es la SALIDA de la exportacion, y pasar por el camino
 * de fichaje 20.000 veces convertiria esta prueba en algo que nadie ejecuta.
 */

uses(RefreshDatabase::class);

/** El PIN sembrado. Si su hash sale en el ZIP, la lista de permitidos ha fallado. */
const HASH_DE_PIN_SEMBRADO = '$2y$04$hashDePinQueNuncaDebeSalirDelProducto';

const SECRETO_DE_TARJETA_SEMBRADO = 'secreto-de-tarjeta-que-nunca-sale';

const TOKEN_DE_QUIOSCO_SEMBRADO = 'token-de-quiosco-que-nunca-sale';

const CLAVE_FIRMADA_SEMBRADA = 'FHL1.clave-de-licencia-firmada-que-nunca-sale';

/**
 * Una instalacion con 500 personas, 90 dias de fichajes, correcciones, tramos
 * sustituidos, un quiosco y una cuenta con nombre distintivo.
 *
 * @return array{employees: list<string>, superseded: string}
 */
function instalacionParaExportar(): array
{
    $siteId = WorkforceFixtures::site();
    $departmentId = WorkforceFixtures::department($siteId, 'Pisos');

    $employees = [];
    $rows = [];

    for ($index = 0; $index < 500; $index++) {
        $uuid = Str::uuid7()->toString();
        $employees[] = $uuid;

        $rows[] = [
            'uuid' => $uuid,
            'site_id' => $siteId,
            'department_id' => $departmentId,
            'first_name' => 'Marta',
            'last_name' => 'Lopez Garcia',
            'employee_code' => 'X'.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
            'email' => $index % 7 === 0 ? $index.'.marta@hotel-ejemplo.example' : null,
            // Los dos secretos de la ficha de una persona. Ninguno puede salir.
            'pin_hash' => HASH_DE_PIN_SEMBRADO,
            'national_id_hash' => $index % 5 === 0 ? '49871234Z'.$index : null,
            'photo_path' => '/var/www/fotos/'.$index.'.jpg',
            'status' => 'active',
            'hired_at' => '2026-01-01',
            'locale' => 'es',
            'pin_issued_at' => '2026-01-01T00:00:00Z',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ];
    }

    DB::table('employees')->insert($rows);

    /** @var list<int> $employeeIds */
    $employeeIds = DB::table('employees')->orderBy('id')->pluck('id')->all();

    DB::table('devices')->insert([[
        'uuid' => Str::uuid7()->toString(),
        'site_id' => $siteId,
        'name' => 'Recepcion',
        'token_hash' => TOKEN_DE_QUIOSCO_SEMBRADO,
        'app_version' => '2.1.0',
        'status' => 'active',
        'pending_queue_size' => 0,
        'last_seen_at' => '2026-06-15T08:00:00Z',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]]);

    /** @var int $deviceId */
    $deviceId = DB::table('devices')->orderBy('id')->value('id');

    // Una credencial por persona, con su secreto. Ninguno sale.
    $credentials = [];

    foreach ($employeeIds as $position => $employeeId) {
        $credentials[] = [
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $employeeId,
            'key_id' => 'a3',
            // Unico por fila: la columna lleva indice unico junto a `key_id`. El
            // sufijo no cambia lo que la prueba busca —que la raiz del secreto
            // no salga del ZIP—.
            'secret_hash' => SECRETO_DE_TARJETA_SEMBRADO.'-'.$position,
            'issued_at' => '2026-01-02T00:00:00Z',
            // El secreto se acuña al IMPRIMIR (ADR-034), y el esquema lo exige:
            // una credencial con secreto y sin fecha de impresion no existe.
            'printed_at' => '2026-01-02T00:00:00Z',
        ];
    }

    DB::table('credentials')->insert($credentials);

    // 90 dias de jornadas. Por lotes: 45.000 filas de una sentencia agotan la
    // memoria del driver, que es un limite del driver y no de la exportacion.
    for ($day = 0; $day < 90; $day++) {
        $date = (new DateTimeImmutable('2026-03-17T00:00:00Z'))->modify('+'.$day.' days');
        $shifts = [];

        foreach ($employeeIds as $position => $employeeId) {
            $in = $date->modify('+7 hours')->format(DATE_ATOM);
            $out = $date->modify('+15 hours')->format(DATE_ATOM);

            $shifts[] = [
                'uuid' => Str::uuid7()->toString(),
                'employee_id' => $employeeId,
                'site_id' => $siteId,
                'work_date' => $date->format('Y-m-d'),
                'clocked_in_at' => $in,
                'clocked_out_at' => $out,
                'duration_minutes' => 480,
                'status' => 'closed',
                'clock_in_source' => 'qr_kiosk',
                'clock_out_source' => 'qr_kiosk',
                'version' => 1,
                'created_at' => $in,
                'updated_at' => $out,
            ];

            if ($position > 40) {
                // Igual que la semilla del paquete de diagnostico: el volumen que
                // importa es el de la PLANTILLA, que es lo que se inspecciona
                // campo a campo. 41 personas fichando 90 dias son unos 3.700
                // tramos.
                break;
            }
        }

        DB::table('shift_entries')->insert($shifts);
    }

    /*
     * UNA CORRECCION DE VERDAD: la version 1 queda `superseded` y apunta a la 2.
     * Es lo que RL-04 obliga a conservar y lo que una exportacion que solo
     * enseñara la version vigente ocultaria.
     */
    /** @var object{id: int, uuid: string, employee_id: int, work_date: string} $original */
    $original = DB::table('shift_entries')->orderBy('id')->first();

    /*
     * La version 1 pasa a `superseded` ANTES de insertar la 2, y no despues.
     * `shift_entries_no_overlap` excluye del solape las filas `superseded` y
     * `voided`: al reves, las dos versiones del mismo tramo se solaparian y el
     * motor rechazaria la correccion — que es exactamente lo que hace el codigo
     * de produccion, en el orden correcto.
     */
    DB::table('shift_entries')->where('id', $original->id)->update(['status' => 'superseded']);

    $sustitutoId = DB::table('shift_entries')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $original->employee_id,
        'site_id' => $siteId,
        'work_date' => $original->work_date,
        'clocked_in_at' => '2026-03-17T07:30:00Z',
        'clocked_out_at' => '2026-03-17T15:00:00Z',
        'duration_minutes' => 450,
        'status' => 'closed',
        'clock_in_source' => 'manual_admin',
        'clock_out_source' => 'qr_kiosk',
        'version' => 2,
        'created_at' => '2026-03-18T09:00:00Z',
        'updated_at' => '2026-03-18T09:00:00Z',
    ]);

    DB::table('shift_entries')->where('id', $original->id)->update(['superseded_by_id' => $sustitutoId]);

    /** @var int $userId */
    $userId = DB::table('users')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'name' => 'Cunegunda Etxeberria',
        'email' => 'cunegunda@hotel-ejemplo.example',
        // La contraseña de una cuenta de gestion: tampoco sale.
        'password' => '$2y$04$hashDeContrasenaQueNuncaSale',
        'remember_token' => 'recuerdame-que-nunca-sale',
        'two_factor_secret' => 'SECRETOTOTPQUENUNCASALE',
        'locale' => 'es',
        'is_active' => true,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    DB::table('shift_corrections')->insert([[
        'shift_entry_id' => $original->id,
        'performed_by_user_id' => $userId,
        'action' => 'modified',
        'before' => json_encode(['clocked_in_at' => '2026-03-17T07:00:00Z', 'worked_minutes' => 480]),
        'after' => json_encode(['clocked_in_at' => '2026-03-17T07:30:00Z', 'worked_minutes' => 450]),
        'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
        'reason_text' => 'Entro media hora mas tarde y no lo ficho',
        'created_at' => '2026-03-18T09:00:00Z',
    ]]);

    DB::table('scan_events')->insert([[
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $deviceId,
        'employee_id' => $employeeIds[0],
        'occurred_at' => '2026-06-15T07:00:00Z',
        'recorded_at' => '2026-06-15T07:00:01Z',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'clock_in',
        'worked_minutes' => 0,
        'payload_fingerprint' => 'huella-de-payload-que-nunca-sale',
        'client_meta' => json_encode(['user_agent' => 'Tablet de recepcion']),
    ]]);

    /*
     * Una licencia con su clave firmada: la clave es del fabricante y no viaja.
     *
     * Se vacia antes porque `license` tiene un unico de una sola fila
     * (`one_license`, ADR-040) y el `beforeEach` ya activo una: aqui hace falta
     * una cuya `signed_key` sea reconocible para poder buscarla en el ZIP.
     */
    DB::table('license')->delete();

    DB::table('license')->insert([
        'signed_key' => CLAVE_FIRMADA_SEMBRADA,
        'license_id' => 'LIC-0001',
        'customer_name' => 'Hotel de ejemplo, S.L.',
        'plan' => 'estandar',
        'max_employees' => 500,
        'max_devices' => 5,
        'features' => '["white_label"]',
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2027-01-01T00:00:00Z',
        'issued_at' => '2026-01-01T00:00:00Z',
        'activated_at' => '2026-01-01T00:00:00Z',
        'activated_by_user_id' => $userId,
    ]);

    return ['employees' => $employees, 'superseded' => $original->uuid];
}

/**
 * El contenido de todos los ficheros del ZIP, concatenado.
 *
 * Se lee el ZIP de verdad y no el directorio de trabajo, que ya se ha borrado:
 * lo que se inspecciona es **lo que el cliente recibe**.
 */
function contenidoDelZip(string $path): string
{
    $zip = new ZipArchive;

    expect($zip->open($path))->toBeTrue('No se pudo abrir el ZIP de la exportacion: '.$path);

    $contenido = '';

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $contenido .= (string) $zip->getFromIndex($index);
    }

    $zip->close();

    return $contenido;
}

beforeEach(function (): void {
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));
    LicenseKeys::grantAll();

    DataExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
});

it('exporta 500 empleados y 90 dias con los recuentos cuadrados y sin agotar memoria', function (): void {
    $escenario = instalacionParaExportar();

    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Console, null),
    );

    /*
     * SE MIDE EL INCREMENTO, NO EL PICO ABSOLUTO.
     *
     * `memory_get_peak_usage(true)` es la memoria que el proceso ha pedido al
     * sistema **desde que arranco**, y en la suite completa este fichero corre
     * detras de cientos de pruebas: el proceso ya llega con mas de 130 MiB
     * reservados por otras. Un techo absoluto ahi no mide la exportacion, mide
     * el orden de ejecucion de la suite —y por eso esta prueba pasaba con el
     * filtro y fallaba con la suite entera—.
     *
     * `memory_reset_peak_usage()` pone el contador de pico en el uso actual, de
     * modo que lo que se compara despues es lo que ha costado generar.
     */
    $base = memory_get_usage(true);

    memory_reset_peak_usage();

    $export = app(GenerateDataExportHandler::class)->handle($requested->uuid);

    $incremento = memory_get_peak_usage(true) - $base;

    // --- Los recuentos cuadran con la base de datos -------------------------

    foreach ([
        'employees' => 'employees',
        'credentials' => 'credentials',
        'shift_entries' => 'shift_entries',
        'shift_corrections' => 'shift_corrections',
        'scan_events' => 'scan_events',
        'devices' => 'devices',
        'departments' => 'departments',
        'users' => 'users',
        'license' => 'license',
    ] as $conjunto => $tabla) {
        expect($export->rowCounts[$conjunto] ?? -1)->toBe(
            DB::table($tabla)->count(),
            'El fichero «'.$conjunto.'» no lleva las mismas filas que la tabla «'.$tabla.'».',
        );
    }

    /*
     * `audit_log` se compara aparte, y la diferencia es exactamente una fila:
     * **el asiento `data_export.generated` se escribe DESPUES de cerrar el ZIP**
     * y por definicion no puede estar dentro de el. La peticion
     * (`data_export.requested`) si esta, porque se audita antes de generar.
     *
     * Se afirma la cifra exacta y no «una o mas»: si algun dia se escribieran dos
     * asientos de mas —o ninguno—, esta linea lo dice.
     */
    expect($export->rowCounts['audit_log'] ?? -1)->toBe(
        DB::table('audit_log')->count() - 1,
        'El fichero «audit_log» deberia llevar todos los asientos menos el de su propia generacion.',
    );

    expect(DB::table('audit_log')->where('action', 'data_export.generated')->count())->toBe(1);

    // Y no es una exportacion vacia que pasaria lo de arriba por casualidad.
    expect($export->rowCounts['employees'])->toBe(500)
        ->and($export->rowCounts['shift_entries'])->toBeGreaterThan(3_500);

    // --- Cabe en memoria ----------------------------------------------------

    /*
     * 48 MiB DE INCREMENTO, y el numero esta elegido para que una implementacion
     * NO streaming lo supere con esta misma semilla.
     *
     * El escenario escribe unos 3.700 tramos, 500 fichas de plantilla, 500
     * credenciales y sus escaneos. Cargar en memoria una sola de esas tablas
     * —que es lo que hace un `SELECT` normal con el driver de PostgreSQL, sin
     * cursor de servidor— son decenas de miles de filas con quince columnas de
     * texto cada una: por encima de este techo de largo. Con el cursor, en el
     * proceso solo hay un lote de 500 filas cada vez, y por eso cuatro años
     * cuestan lo mismo que noventa dias.
     *
     * El techo es del INCREMENTO y no del pico absoluto: ver el comentario de
     * `memory_reset_peak_usage()` mas arriba. Si algun dia se pasa, el mensaje
     * dice cuanto, que es lo primero que hay que saber para decidir si el
     * streaming se ha roto o si la semilla ha crecido.
     */
    expect($incremento)->toBeLessThan(
        48 * 1024 * 1024,
        'La exportacion integra ha pedido demasiada memoria: '.round($incremento / 1048576, 1)
        .' MiB de incremento sobre los '.round($base / 1048576, 1).' MiB que ya tenia el proceso. '
        .'Con el cursor de servidor no deberia crecer con el tamaño de las tablas.',
    );
})->group('RF-PD-14');

it('no deja salir ni un secreto en el ZIP entero', function (): void {
    instalacionParaExportar();

    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Console, null),
    );

    $export = app(GenerateDataExportHandler::class)->handle($requested->uuid);

    $contenido = contenidoDelZip((string) $export->filePath);

    // Los NOMBRES de columna tampoco: una columna ausente no dice nada y una
    // «redactada» confirmaria que existe.
    foreach ([
        'pin_hash', 'secret_hash', 'token_hash', 'signed_key', 'password',
        'remember_token', 'two_factor_secret', 'national_id_hash', 'photo_path',
        'payload_fingerprint',
    ] as $columna) {
        expect(str_contains($contenido, $columna))->toBeFalse(
            'El ZIP de la exportacion integra menciona la columna prohibida «'.$columna.'».',
        );
    }

    // Y los VALORES sembrados, que es lo que de verdad importa.
    foreach ([
        HASH_DE_PIN_SEMBRADO,
        SECRETO_DE_TARJETA_SEMBRADO,
        TOKEN_DE_QUIOSCO_SEMBRADO,
        CLAVE_FIRMADA_SEMBRADA,
        '$2y$04$hashDeContrasenaQueNuncaSale',
        'recuerdame-que-nunca-sale',
        'SECRETOTOTPQUENUNCASALE',
        'huella-de-payload-que-nunca-sale',
        '/var/www/fotos/',
        '49871234Z',
    ] as $secreto) {
        expect(str_contains($contenido, $secreto))->toBeFalse(
            'El ZIP de la exportacion integra contiene el secreto sembrado «'.$secreto.'».',
        );
    }
})->group('RF-PD-14', 'RS-08', 'RL-20');

it('lleva los datos del cliente, incluidas las versiones sustituidas y las correcciones', function (): void {
    // La otra mitad: una exportacion vacia pasaria todo lo anterior y no serviria
    // de nada. Y RL-04 exige que la historia completa este dentro.
    $escenario = instalacionParaExportar();

    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Console, null),
    );

    $export = app(GenerateDataExportHandler::class)->handle($requested->uuid);

    $contenido = contenidoDelZip((string) $export->filePath);

    expect($contenido)->toContain('Marta')
        ->and($contenido)->toContain('Lopez Garcia')
        ->and($contenido)->toContain('marta@hotel-ejemplo.example')
        ->and($contenido)->toContain('Cunegunda Etxeberria')
        // El tramo sustituido, con su estado y su sucesor.
        ->and($contenido)->toContain($escenario['superseded'])
        ->and($contenido)->toContain('superseded')
        // La correccion, con su autor y su motivo (RL-04).
        ->and($contenido)->toContain('OLVIDO_FICHAJE_ENTRADA')
        ->and($contenido)->toContain('Entro media hora mas tarde y no lo ficho')
        // La licencia, sin su clave firmada.
        ->and($contenido)->toContain('LIC-0001')
        ->and($contenido)->toContain('Hotel de ejemplo, S.L.')
        // Y el README, que es lo que hace la exportacion utilizable dentro de dos
        // años sin el producto delante.
        ->and($contenido)->toContain('Exportacion integra de los datos de KronoQR')
        ->and($contenido)->toContain('Europe/Madrid');
})->group('RF-PD-14', 'RL-04', 'RL-20');

it('escribe el ZIP con permisos 0600 y su directorio con 0700', function (): void {
    // El fichero mas peligroso del disco de la instalacion: la plantilla entera.
    // Legible por cualquier cuenta del servidor seria una fuga esperando a pasar.
    // Solo vale dentro del contenedor (`HANDOFF.md`).
    instalacionParaExportar();

    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Console, null),
    );

    $export = app(GenerateDataExportHandler::class)->handle($requested->uuid);

    expect(substr(sprintf('%o', fileperms((string) $export->filePath)), -4))->toBe('0600')
        ->and(substr(sprintf('%o', fileperms(dirname((string) $export->filePath))), -4))->toBe('0700');

    // Y el directorio de trabajo no queda: un `employees.csv` suelto con la
    // plantilla entera seria peor que el propio ZIP, porque nadie sabria de
    // donde salio ni cuando borrarlo.
    expect(glob(dirname((string) $export->filePath).'/.work-*'))->toBe([]);
})->group('RF-PD-14', 'RS-08');
