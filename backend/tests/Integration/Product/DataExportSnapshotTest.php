<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\RequestDataExportCommand;
use App\Modules\Product\Application\Port\DataExportArchiveWriter;
use App\Modules\Product\Application\UseCase\GenerateDataExportHandler;
use App\Modules\Product\Application\UseCase\RequestDataExportHandler;
use App\Modules\Product\Domain\ValueObject\DataExportArchive;
use App\Modules\Product\Domain\ValueObject\DataExportFile;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Product\Domain\ValueObject\ExportedDataset;
use App\Modules\Product\Infrastructure\Export\ZipDataExportArchiveWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Product\DataExports;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **La exportacion integra es una FOTO DE UN INSTANTE, y esta prueba es la unica
 * que lo demuestra** (RF-PD-14, RL-20, RL-04).
 *
 * ## Que fallo protege
 *
 * Los dieciocho conjuntos se leen con dieciocho cursores de servidor distintos.
 * En `READ COMMITTED` —el nivel por omision de PostgreSQL y el que usa este
 * producto— **cada `DECLARE ... CURSOR` toma su propia instantanea al
 * declararse**, no al abrirse la transaccion. Un hotel ficha a las 06:00 y a las
 * 22:00, y una exportacion tarda minutos: un fichaje ocurrido por medio aparece
 * en unos ficheros y falta en otros.
 *
 * El resultado no es «un dato de mas»: es un `scan_events.csv` con un
 * `shift_entry_uuid` que no existe en `shift_entries.csv`, y unos
 * `daily_totals.csv` que no cuadran con los tramos. Es decir, **una copia de
 * respaldo internamente incoherente de un registro con valor legal**, entregada
 * como la garantia de continuidad del cliente. El manifiesto afirma un
 * `generated_at`; esto es lo que hace que esa afirmacion sea cierta.
 *
 * Lo arregla una linea —`SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` como
 * primera sentencia de la transaccion— y sin esta prueba nada la sostendria:
 * quitarla no rompe nada visible, y el sintoma aparecería meses despues en casa
 * de un cliente, en un fichero que nadie revisa hasta que hace falta.
 *
 * ## Por que `CommittedDatabase` y una segunda conexion
 *
 * La escritura «de fuera» tiene que estar **confirmada** para que la transaccion
 * de la exportacion pueda verla; con la transaccion envolvente de
 * `RefreshDatabase` no habria nada que ver y la prueba pasaria por vacio. Se usa
 * la conexion del rol de migracion, que es otra sesion de PostgreSQL contra la
 * misma base: exactamente lo que es un quiosco fichando mientras se exporta.
 *
 * ## Por que se inyecta a mitad con un decorador del escritor
 *
 * Porque la incoherencia solo se produce **entre** dos `DECLARE`. Un decorador
 * que escribe la fila justo despues del primer conjunto reproduce el instante
 * exacto en el que ocurre, y deja intacto el resto del camino: se genera el ZIP
 * de verdad y se inspecciona el ZIP de verdad.
 */

uses(CommittedDatabase::class);

/**
 * Un escritor que se comporta igual que el de produccion y ademas ejecuta algo
 * **una sola vez**, justo despues de escribir el primer conjunto.
 */
function escritorQueInterrumpe(string $directory, Closure $interrupcion): DataExportArchiveWriter
{
    return new class($directory, $interrupcion) implements DataExportArchiveWriter
    {
        private bool $interrumpido = false;

        private readonly ZipDataExportArchiveWriter $real;

        public function __construct(string $directory, private readonly Closure $interrupcion)
        {
            $this->real = new ZipDataExportArchiveWriter($directory);
        }

        public function begin(string $exportUuid): string
        {
            return $this->real->begin($exportUuid);
        }

        public function writeDataset(string $workspace, ExportedDataset $dataset, iterable $rows, string $locale): DataExportFile
        {
            $file = $this->real->writeDataset($workspace, $dataset, $rows, $locale);

            if (! $this->interrumpido) {
                $this->interrumpido = true;

                ($this->interrupcion)();
            }

            return $file;
        }

        public function writeDocument(string $workspace, string $fileName, string $contents): DataExportFile
        {
            return $this->real->writeDocument($workspace, $fileName, $contents);
        }

        public function seal(string $workspace, string $fileName): DataExportArchive
        {
            return $this->real->seal($workspace, $fileName);
        }

        public function discard(string $workspace): void
        {
            $this->real->discard($workspace);
        }

        public function delete(string $path): bool
        {
            return $this->real->delete($path);
        }
    };
}

/** El contenido de todos los ficheros del ZIP, concatenado. */
function contenidoDelZipDeInstantanea(string $path): string
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

it('no incluye nada escrito por otra conexion mientras se estaba generando', function (): void {
    $directorio = DataExports::useTemporaryPath();

    $siteId = WorkforceFixtures::site();
    // `employee()` devuelve el identificador PUBLICO; aqui hace falta la clave
    // interna porque se escriben filas con el constructor de consultas.
    $employeeUuid = WorkforceFixtures::employee($siteId);

    /** @var int $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $employeeUuid)->value('id');

    // Un tramo que SI existe antes de empezar: el control de que la exportacion
    // no sale vacia y de que la prueba mira donde tiene que mirar.
    $anterior = Str::uuid7()->toString();

    DB::table('shift_entries')->insert([
        'uuid' => $anterior,
        'employee_id' => $employeeId,
        'site_id' => $siteId,
        'work_date' => '2026-03-17',
        'clocked_in_at' => '2026-03-17T07:00:00Z',
        'clocked_out_at' => '2026-03-17T15:00:00Z',
        'duration_minutes' => 480,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => '2026-03-17T07:00:00Z',
        'updated_at' => '2026-03-17T15:00:00Z',
    ]);

    // El tramo «del fichaje de las 06:00»: lo escribe OTRA SESION de PostgreSQL
    // mientras la exportacion ya ha empezado a leer.
    $intruso = Str::uuid7()->toString();

    app()->bind(
        DataExportArchiveWriter::class,
        static fn (): DataExportArchiveWriter => escritorQueInterrumpe(
            $directorio,
            static function () use ($intruso, $employeeId, $siteId): void {
                DB::connection('pgsql_migrator')->table('shift_entries')->insert([
                    'uuid' => $intruso,
                    'employee_id' => $employeeId,
                    'site_id' => $siteId,
                    'work_date' => '2026-03-18',
                    'clocked_in_at' => '2026-03-18T06:00:00Z',
                    'clocked_out_at' => '2026-03-18T14:00:00Z',
                    'duration_minutes' => 480,
                    'status' => 'closed',
                    'clock_in_source' => 'qr_kiosk',
                    'clock_out_source' => 'qr_kiosk',
                    'version' => 1,
                    'created_at' => '2026-03-18T06:00:00Z',
                    'updated_at' => '2026-03-18T14:00:00Z',
                ]);
            },
        ),
    );

    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Console, null),
    );

    $export = app(GenerateDataExportHandler::class)->handle($requested->uuid);

    $contenido = contenidoDelZipDeInstantanea((string) $export->filePath);

    // El control: lo que ya existia si esta.
    // `toContain($x, $mensaje)` trataria el mensaje como un segundo texto a
    // buscar (trampa conocida de Pest, `HANDOFF.md`): para poder explicar el
    // fallo hay que afirmar el booleano.
    expect(str_contains($contenido, $anterior))->toBeTrue(
        'La exportacion no lleva ni el tramo que existia antes de empezar: la prueba no esta midiendo nada.',
    );

    // Y la afirmacion: lo escrito por otra conexion a mitad **no aparece en
    // NINGUN fichero**. Sin `REPEATABLE READ` aparecia en los conjuntos leidos
    // despues de la interrupcion y faltaba en los anteriores.
    expect(str_contains($contenido, $intruso))->toBeFalse(
        'La exportacion ha incluido un tramo escrito por otra conexion mientras se generaba. '
        .'Los cursores no comparten instantanea: falta `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`.',
    );

    // Y la fila del intruso existe de verdad: si el `INSERT` no se hubiera hecho,
    // la afirmacion de arriba pasaria por vacio.
    expect(DB::table('shift_entries')->where('uuid', $intruso)->exists())->toBeTrue();

    DataExports::cleanUpTemporaryPath();
})->group('RF-PD-14', 'RL-20', 'RL-04');
