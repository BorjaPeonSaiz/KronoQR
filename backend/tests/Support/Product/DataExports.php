<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Port\DataExportArchiveWriter;
use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Product\Infrastructure\Export\ZipDataExportArchiveWriter;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Exportaciones integras para las pruebas (**RF-PD-14**, RL-20, tarea 5.10).
 *
 * ## Por que hace falta un directorio propio por prueba
 *
 * El escritor deja ficheros de verdad en el disco, y la suite corre muchas veces
 * seguidas en el mismo contenedor. Sin aislarlo, una prueba veria los ZIP de la
 * anterior —y, peor, la purga de una borraria los ficheros de otra—. Cada prueba
 * que genere de verdad llama a {@see self::useTemporaryPath()} en su `beforeEach`.
 *
 * ## Las filas se fabrican a mano; los ficheros, no
 *
 * `stub()` escribe una fila directamente con el constructor de consultas: las
 * pruebas de listado, descarga y purga necesitan una exportacion **ya
 * terminada** y pasar por la generacion real cada vez convertiria una prueba de
 * cabeceras HTTP en una prueba de rendimiento. Lo que si es real es el fichero
 * que acompaña a esa fila, porque la descarga lo abre.
 */
final class DataExports
{
    /**
     * El ultimo directorio temporal entregado.
     *
     * **Vive aqui y no en `$this->…` de la prueba** a proposito: dentro de una
     * clausura de Pest, `$this` es un `TestCall` y PHPStan 9 no le resuelve
     * ninguna propiedad —la suite entera saldria con errores de tipo o, peor,
     * con supresiones—. Es la misma razon por la que existen los helpers `Api` y
     * `Commands`.
     */
    private static string $temporaryPath = '';

    /**
     * Deja `product.data_export_path` en un directorio temporal propio y
     * devuelve su ruta.
     *
     * Se reconstruye el escritor porque recibe la ruta por constructor (regla
     * dura 14: el umbral entra ya resuelto), asi que cambiar la configuracion
     * despues de que el contenedor lo haya resuelto no serviria de nada.
     */
    public static function useTemporaryPath(): string
    {
        $path = sys_get_temp_dir().'/kronoqr-export-test-'.bin2hex(random_bytes(6));

        config(['product.data_export_path' => $path]);

        app()->bind(
            DataExportArchiveWriter::class,
            static fn (): ZipDataExportArchiveWriter => new ZipDataExportArchiveWriter($path),
        );

        return self::$temporaryPath = $path;
    }

    /** Borra el ultimo directorio temporal. Se llama en el `afterEach`. */
    public static function cleanUpTemporaryPath(): void
    {
        if (self::$temporaryPath === '') {
            return;
        }

        self::cleanUp(self::$temporaryPath);

        self::$temporaryPath = '';
    }

    /**
     * Una exportacion ya terminada, con su fichero de verdad en el disco.
     *
     * @param  array<string, int>  $rowCounts
     */
    public static function completed(
        ?string $uuid = null,
        ?int $requestedByUserId = null,
        string $requestedVia = 'panel',
        array $rowCounts = ['employees' => 3],
        ?DateTimeImmutable $expiresAt = null,
        string $contents = 'PK-contenido-de-prueba',
    ): DataExport {
        $uuid ??= Str::uuid7()->toString();
        $fileName = 'kronoqr-export-2.1.0-'.strtoupper(bin2hex(random_bytes(4))).'.zip';

        /** @var string $directory */
        $directory = config('product.data_export_path');

        if (! is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }

        $path = $directory.'/'.$fileName;
        file_put_contents($path, $contents);
        chmod($path, 0o600);

        DB::table('data_exports')->insert([
            'uuid' => $uuid,
            'requested_by_user_id' => $requestedByUserId,
            'requested_via' => $requestedVia,
            'status' => 'completed',
            'requested_at' => self::utc('2026-09-08T10:00:00Z'),
            'started_at' => self::utc('2026-09-08T10:00:01Z'),
            'completed_at' => self::utc('2026-09-08T10:00:30Z'),
            'file_path' => $path,
            'file_name' => $fileName,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'row_counts' => json_encode($rowCounts, JSON_THROW_ON_ERROR),
            'expires_at' => self::utc(($expiresAt ?? new DateTimeImmutable('2036-01-01T00:00:00Z'))->format(DATE_ATOM)),
            'download_count' => 0,
            'created_at' => self::utc('2026-09-08T10:00:00Z'),
            'updated_at' => self::utc('2026-09-08T10:00:30Z'),
        ]);

        return self::find($uuid);
    }

    /**
     * Una exportacion que ocupa el turno: `pending` o `running`.
     *
     * **Recien pedida, con el reloj de la instalacion**, y no con una fecha fija.
     * Desde la segunda vuelta hay una barrida de obsolescencia que declara
     * `failed`/`stale` toda fila sin terminar mas vieja que
     * `PRODUCT_DATA_EXPORT_STALE_AFTER`: con una fecha literal, cada prueba que
     * solo queria «hay una en curso» acabaria comprobando la obsolescencia sin
     * saberlo, y el resultado dependeria de que dia se ejecute la suite.
     *
     * Las pruebas que SI quieren una atascada la envejecen a proposito con un
     * `UPDATE` explicito, que se lee como lo que es.
     */
    public static function inProgress(string $status = 'pending', ?string $uuid = null): DataExport
    {
        $uuid ??= Str::uuid7()->toString();

        /** @var Clock $clock */
        $clock = app(Clock::class);

        $now = self::utc($clock->now()->format(DateTimeInterface::RFC3339_EXTENDED));

        DB::table('data_exports')->insert([
            'uuid' => $uuid,
            'requested_by_user_id' => null,
            'requested_via' => 'panel',
            'status' => $status,
            'requested_at' => $now,
            'started_at' => $status === 'running' ? $now : null,
            'row_counts' => '{}',
            'download_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::find($uuid);
    }

    public static function find(string $uuid): DataExport
    {
        /** @var DataExportRepository $repository */
        $repository = app(DataExportRepository::class);

        $export = $repository->findByUuid($uuid);

        if ($export === null) {
            throw new \RuntimeException('No existe la exportacion de prueba '.$uuid);
        }

        return $export;
    }

    /** Borra el directorio temporal. Se llama en el `afterEach`. */
    public static function cleanUp(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                foreach (glob($entry.'/*') ?: [] as $inner) {
                    @unlink($inner);
                }

                @rmdir($entry);

                continue;
            }

            @unlink($entry);
        }

        @rmdir($directory);
    }

    private static function utc(string $instant): string
    {
        return (new DateTimeImmutable($instant, new DateTimeZone('UTC')))
            ->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
