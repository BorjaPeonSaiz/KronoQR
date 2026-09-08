<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Infrastructure\Diagnostics\UpdateReportAllowlist;

/**
 * Seccion `updates`: el informe de la **ultima** actualizacion y la lista de los
 * anteriores (tarea 5.7, doc 02 §11.6.6).
 *
 * ## Por que va en el paquete
 *
 * Porque «desde cuando pasa» y «que cambio antes de que empezara a pasar» son
 * las dos preguntas que abren cualquier incidencia, y la respuesta a las dos
 * esta en `BACKUP_PATH/reports/update-*.log`. Sin esta seccion, la conversacion
 * empieza pidiendo al cliente que entre por SSH a buscar un fichero.
 *
 * ## El informe se filtra POR LINEA, no se vuelca
 *
 * Era la unica seccion del paquete sin lista de permitidos: se copiaba el
 * fichero entero fiandose de que `update.sh` no escribe datos personales en el.
 * Esa promesa es cierta hoy y no se puede confiar: un `err` mal puesto en una
 * version futura, o un mensaje del motor que se cuele por ahi, acabaria en un
 * fichero que el cliente envia al fabricante sin que nada fallara. El filtro
 * vive en {@see UpdateReportAllowlist}, y lo que no reconoce se **cuenta** en
 * `omitted_lines` para que soporte sepa que hay mas y lo pida.
 *
 * ## El `.detalle.log` NUNCA sale, y no es un olvido
 *
 * `update.sh` escribe dos ficheros a proposito: el informe (`0640`, legible por
 * la aplicacion) y el detalle (`0600` de root). El segundo lleva la salida
 * completa de las migraciones y de los contenedores, que **puede contener datos
 * personales** —un error de restriccion con el valor de la fila— y por eso el
 * instalador le pone unos permisos que esta aplicacion no puede sortear. Aqui se
 * repite la decision de forma explicita: se leen solo los `update-*.log`, y el
 * filtro descarta cualquier cosa que contenga `.detalle.`.
 */
final readonly class UpdatesCollector implements DiagnosticsCollector
{
    /** Tope del informe leido. Uno real ocupa unos pocos KB. */
    private const int MAX_REPORT_BYTES = 256 * 1024;

    /** Informes listados. Mas alla, la lista deja de aportar. */
    private const int MAX_LISTED = 20;

    public function __construct(private string $backupPath) {}

    public function section(): string
    {
        return 'updates';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        $directory = rtrim($this->backupPath, '/').'/reports';

        if (! is_dir($directory) || ! is_readable($directory)) {
            return [
                'status' => 'unavailable',
                'reason' => 'reports_directory_unreadable',
                'path' => $directory,
            ];
        }

        $reports = $this->reports($directory);

        if ($reports === []) {
            // No es un fallo: una instalacion recien montada no se ha
            // actualizado nunca, y decir «no hay informes» es informacion.
            return ['status' => 'no_reports', 'path' => $directory];
        }

        $latest = $reports[0];

        return [
            'path' => $directory,
            'reports' => array_map(
                static fn (string $file): array => [
                    'file' => basename($file),
                    'bytes' => (int) filesize($file),
                    'modified_at' => gmdate(DATE_ATOM, (int) filemtime($file)),
                ],
                $reports,
            ),
            'latest' => [
                'file' => basename($latest),
                'truncated' => (int) filesize($latest) > self::MAX_REPORT_BYTES,
                ...UpdateReportAllowlist::apply($this->read($latest)),
            ],
        ];
    }

    /**
     * Los informes, del mas reciente al mas antiguo.
     *
     * @return list<string>
     */
    private function reports(string $directory): array
    {
        $found = glob($directory.'/update-*.log');

        if ($found === false) {
            return [];
        }

        // El detalle es 0600 de root y puede llevar datos personales. Fuera, de
        // forma explicita, aunque los permisos ya lo impidieran: los permisos
        // son del despliegue y esto es una decision del producto.
        $reports = array_values(array_filter(
            $found,
            static fn (string $file): bool => ! str_contains(basename($file), '.detalle.') && is_readable($file),
        ));

        usort($reports, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));

        return \array_slice($reports, 0, self::MAX_LISTED);
    }

    private function read(string $file): string
    {
        $content = file_get_contents($file, false, null, 0, self::MAX_REPORT_BYTES);

        return $content === false ? '' : $content;
    }
}
