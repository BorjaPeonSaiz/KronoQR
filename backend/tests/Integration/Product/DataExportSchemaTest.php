<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DataExportFailure;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Product\Domain\ValueObject\DataExportStatus;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;

/*
 * El esquema de `data_exports` dice lo mismo que el codigo, y sus invariantes
 * estan VIGENTES (RF-PD-14, RNF-D-04).
 *
 * ## Por que hace falta una prueba para esto
 *
 * La migracion compone sus dos `CHECK` a partir de los enums del dominio, asi
 * que en teoria no pueden separarse. En la practica si: una migracion ya
 * aplicada en casa de un cliente **no se reescribe**, de modo que un estado
 * nuevo en `DataExportStatus` seis meses despues dejaria la instalacion antigua
 * con un `CHECK` que lo rechaza. Eso no falla al desplegar: falla la primera vez
 * que alguien pide una exportacion.
 *
 * La invariante que mas importa es la tercera —**una sola exportacion en curso**—
 * y esa no se puede comprobar leyendo codigo: es un indice unico parcial, y lo
 * unico que demuestra que funciona es intentar insertar la segunda fila.
 */

uses(RefreshDatabase::class);

/**
 * La definicion que PostgreSQL guarda de una restriccion o de un indice.
 *
 * Se pregunta al catalogo del motor y no a la migracion: lo que importa no es lo
 * que el fichero dice que iba a crear, sino lo que la base de datos **tiene**.
 */
function definicionDeExportacion(string $nombre): string
{
    $restricciones = DB::select(
        'SELECT pg_get_constraintdef(oid) AS definicion FROM pg_constraint WHERE conname = ?',
        [$nombre],
    );

    if ($restricciones !== []) {
        $definicion = $restricciones[0]->definicion ?? null;

        return \is_string($definicion) ? $definicion : '';
    }

    $indices = DB::select('SELECT indexdef AS definicion FROM pg_indexes WHERE indexname = ?', [$nombre]);

    if ($indices === []) {
        return '';
    }

    $definicion = $indices[0]->definicion ?? null;

    return \is_string($definicion) ? $definicion : '';
}

/**
 * Inserta una fila de exportacion con el estado que se le diga.
 *
 * Con el constructor de consultas y no por el caso de uso: lo que se prueba aqui
 * es la BASE DE DATOS, y pasar por el caso de uso comprobaria ademas la
 * comprobacion de PHP, que es justo la que no se quiere ejercitar.
 */
function insertaExportacion(string $uuid, string $estado = 'pending', string $via = 'panel'): void
{
    /*
     * DENTRO DE `DB::transaction()`, y no es un adorno.
     *
     * `RefreshDatabase` envuelve cada prueba en una transaccion. Cuando una
     * sentencia falla, PostgreSQL aborta la transaccion entera y **todo lo que
     * venga despues falla con `25P02`**, incluida la consulta con la que esta
     * prueba quiere comprobar que la fila no se escribio. Anidar una transaccion
     * hace que Laravel abra un `SAVEPOINT`: el fallo deshace solo hasta ahi y la
     * prueba puede seguir preguntando.
     */
    DB::transaction(static fn () => DB::table('data_exports')->insert([
        'uuid' => $uuid,
        'requested_by_user_id' => null,
        'requested_via' => $via,
        'status' => $estado,
        'requested_at' => '2026-09-08 10:00:00+00',
        'row_counts' => '{}',
        'download_count' => 0,
        'created_at' => '2026-09-08 10:00:00+00',
        'updated_at' => '2026-09-08 10:00:00+00',
    ]));
}

it('el CHECK de status admite exactamente los estados del enum', function (): void {
    $definicion = definicionDeExportacion('data_exports_chk_status');

    expect($definicion)->not->toBe('', 'No existe la restriccion `data_exports_chk_status`.');

    preg_match_all("/'([a-z_]+)'/", $definicion, $matches);

    $enElEsquema = array_values(array_unique($matches[1]));
    $enElCodigo = DataExportStatus::names();

    sort($enElEsquema);
    sort($enElCodigo);

    expect($enElEsquema)->toBe(
        $enElCodigo,
        'El CHECK de `data_exports.status` y `DataExportStatus` han dejado de decir lo mismo. '
        .'Esquema: '.implode(', ', $enElEsquema).' · Codigo: '.implode(', ', $enElCodigo),
    );
})->group('RF-PD-14', 'RNF-D-04');

it('el CHECK de requested_via admite exactamente los origenes del enum', function (): void {
    $definicion = definicionDeExportacion('data_exports_chk_requested_via');

    expect($definicion)->not->toBe('', 'No existe la restriccion `data_exports_chk_requested_via`.');

    preg_match_all("/'([a-z_]+)'/", $definicion, $matches);

    $enElEsquema = array_values(array_unique($matches[1]));
    $enElCodigo = DataExportOrigin::names();

    sort($enElEsquema);
    sort($enElCodigo);

    expect($enElEsquema)->toBe($enElCodigo);
})->group('RF-PD-14', 'RNF-D-04');

it('la base de datos rechaza un estado que el enum no conoce', function (): void {
    // La otra mitad: que el `CHECK` este VIGENTE y no solo declarado. Es la red
    // que atrapa un `UPDATE` hecho a mano en una madrugada de incidencia.
    expect(static fn () => insertaExportacion('0199f6a2-1000-7000-8000-000000000001', 'archivado'))
        ->toThrow(QueryException::class);

    expect(DB::table('data_exports')->count())->toBe(0);
})->group('RF-PD-14', 'RNF-D-04');

it('solo deja existir UNA exportacion pendiente o en curso a la vez', function (string $primero, string $segundo): void {
    /*
     * LA INVARIANTE CENTRAL DE LA TABLA, y la unica que ninguna comprobacion en
     * PHP puede garantizar: dos pestañas del mismo administrador pulsando el
     * boton a la vez pasarian cualquier `SELECT` previo. Aqui se prueba lo que
     * de verdad las para, que es el indice unico parcial.
     */
    insertaExportacion('0199f6a2-1000-7000-8000-000000000010', $primero);

    expect(static fn () => insertaExportacion('0199f6a2-1000-7000-8000-000000000011', $segundo))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(DB::table('data_exports')->count())->toBe(1);
})->with([
    'dos pendientes' => ['pending', 'pending'],
    'una pendiente y una en curso' => ['pending', 'running'],
    'una en curso y una pendiente' => ['running', 'pending'],
    'dos en curso' => ['running', 'running'],
])->group('RF-PD-14');

it('deja acumular todas las terminadas, fallidas y purgadas que hagan falta', function (): void {
    // La otra mitad del indice parcial: si cubriera todos los estados, una
    // instalacion no podria pedir una segunda exportacion en su vida.
    insertaExportacion('0199f6a2-1000-7000-8000-000000000020', 'failed');
    insertaExportacion('0199f6a2-1000-7000-8000-000000000021', 'purged');
    insertaExportacion('0199f6a2-1000-7000-8000-000000000022', 'failed');

    DB::table('data_exports')->insert([
        'uuid' => '0199f6a2-1000-7000-8000-000000000023',
        'requested_via' => 'console',
        'status' => 'completed',
        'requested_at' => '2026-09-08 10:00:00+00',
        'completed_at' => '2026-09-08 10:05:00+00',
        'file_name' => 'kronoqr-export-2.1.0-20260908T100500Z.zip',
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 1024,
        'row_counts' => '{}',
        'download_count' => 0,
        'created_at' => '2026-09-08 10:00:00+00',
        'updated_at' => '2026-09-08 10:05:00+00',
    ]);

    // Y ademas queda sitio para una nueva peticion.
    insertaExportacion('0199f6a2-1000-7000-8000-000000000024', 'pending');

    expect(DB::table('data_exports')->count())->toBe(5);
})->group('RF-PD-14', 'RN-13');

it('no deja marcar como terminada una exportacion sin fichero', function (): void {
    /*
     * El peor desenlace posible de esta tarea: una fila `completed` que el panel
     * ofrece descargar y que devuelve `404`. El cliente creeria tener su copia
     * de seguridad y no la tendria — y lo descubriria el dia que la necesita.
     */
    insertaExportacion('0199f6a2-1000-7000-8000-000000000030');

    // Con `SAVEPOINT`, por lo mismo que `insertaExportacion()`.
    expect(static fn () => DB::transaction(static fn () => DB::table('data_exports')
        ->where('uuid', '0199f6a2-1000-7000-8000-000000000030')
        ->update(['status' => 'completed', 'completed_at' => '2026-09-08 10:05:00+00'])
    ))->toThrow(QueryException::class);

    expect(DB::table('data_exports')->where('status', 'completed')->count())->toBe(0);
})->group('RF-PD-14');

it('el indice de exclusion mutua solo cubre pending y running', function (): void {
    // Se lee la definicion del indice y no solo su efecto: si alguien ampliara
    // el `WHERE` a `completed`, la prueba de arriba seguiria pasando y una
    // instalacion no podria pedir su segunda exportacion nunca mas.
    $definicion = definicionDeExportacion('data_exports_single_in_progress_uidx');

    expect($definicion)->not->toBe('', 'No existe el indice `data_exports_single_in_progress_uidx`.')
        ->and($definicion)->toContain('UNIQUE')
        ->and($definicion)->toContain("'pending'")
        ->and($definicion)->toContain("'running'")
        ->and($definicion)->not->toContain("'completed'")
        ->and($definicion)->not->toContain("'purged'");
})->group('RF-PD-14');

it('conserva la exportacion cuando se borra la cuenta que la pidio', function (): void {
    // `nullOnDelete` y no `restrict`: lo que no puede pasar es que la lista deje
    // de poder leerse porque se borro una cuenta. Quien la pidio sigue en
    // `audit_log`.
    $usuario = DB::table('users')->insertGetId([
        'uuid' => '0199f6a2-1000-7000-8000-0000000000f0',
        'name' => 'Cuenta de prueba',
        'email' => 'esquema-export@kronoqr.test',
        'password' => 'irrelevante',
        'locale' => 'es',
        'is_active' => true,
        'created_at' => '2026-09-08 10:00:00+00',
        'updated_at' => '2026-09-08 10:00:00+00',
    ]);

    DB::table('data_exports')->insert([
        'uuid' => '0199f6a2-1000-7000-8000-000000000040',
        'requested_by_user_id' => $usuario,
        'requested_via' => 'panel',
        'status' => 'failed',
        'requested_at' => '2026-09-08 10:00:00+00',
        'row_counts' => '{}',
        'download_count' => 0,
        'created_at' => '2026-09-08 10:00:00+00',
        'updated_at' => '2026-09-08 10:00:00+00',
    ]);

    DB::table('users')->where('id', $usuario)->delete();

    $fila = DB::table('data_exports')->where('uuid', '0199f6a2-1000-7000-8000-000000000040')->first();

    expect($fila)->not->toBeNull()
        ->and($fila?->requested_by_user_id)->toBeNull()
        // Y `requested_via` sigue diciendo la verdad: se pidio desde el panel,
        // aunque ya no se sepa quien. Por eso es un dato y no se deduce del
        // usuario nulo.
        ->and($fila?->requested_via)->toBe('panel');
})->group('RF-PD-14', 'RN-13');

it('el CHECK de failure_reason admite exactamente los codigos del enum', function (): void {
    // La tercera copia del mismo catalogo —enum, esquema y contrato— y la que
    // impide que vuelva a colarse en esa columna el nombre de una clase de PHP o,
    // peor, el mensaje de un error con el valor de una fila dentro (regla dura
    // 21).
    $definicion = definicionDeExportacion('data_exports_chk_failure_reason');

    expect($definicion)->not->toBe('', 'No existe la restriccion `data_exports_chk_failure_reason`.');

    preg_match_all("/'([a-z_]+)'/", $definicion, $matches);

    $enElEsquema = array_values(array_unique($matches[1]));
    $enElCodigo = DataExportFailure::names();

    sort($enElEsquema);
    sort($enElCodigo);

    expect($enElEsquema)->toBe(
        $enElCodigo,
        'El CHECK de `data_exports.failure_reason` y `DataExportFailure` han dejado de decir lo mismo. '
        .'Esquema: '.implode(', ', $enElEsquema).' · Codigo: '.implode(', ', $enElCodigo),
    );
})->group('RF-PD-14', 'RNF-D-04');

it('la base de datos rechaza un motivo de fallo que el enum no conoce', function (): void {
    // Que el `CHECK` este VIGENTE y no solo declarado: es la red que atrapa un
    // `UPDATE` a mano que metiera ahi el texto de una excepcion.
    insertaExportacion('0199f6a2-1000-7000-8000-000000000050');

    expect(static fn () => DB::transaction(static fn () => DB::table('data_exports')
        ->where('uuid', '0199f6a2-1000-7000-8000-000000000050')
        ->update([
            'status' => 'failed',
            'failed_at' => '2026-09-08 10:05:00+00',
            'failure_reason' => 'SQLSTATE[23505]: llave duplicada en la fila de Marta',
        ])
    ))->toThrow(QueryException::class);

    expect(DB::table('data_exports')->whereNotNull('failure_reason')->count())->toBe(0);
})->group('RF-PD-14', 'RNF-D-04');
