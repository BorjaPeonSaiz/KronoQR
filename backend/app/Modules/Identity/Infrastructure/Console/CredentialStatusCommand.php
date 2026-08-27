<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Query\CredentialStatusQuery;
use App\Modules\Identity\Application\UseCase\CredentialStatusBoard;
use App\Modules\Identity\Application\UseCase\CredentialStatusRow;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Identity\Domain\ValueObject\CredentialLifecycleStatus;
use App\Modules\Identity\Domain\ValueObject\SiteCredentialCoverage;
use Illuminate\Console\Command;

/**
 * `php artisan credentials:status --pending` — **quien no puede fichar todavia**
 * (Anexo C del doc 02, RF-QR-08).
 *
 * El doc 02 §5.5 lo justifica: *«RF-QR-08 existe para que RRHH vea de un vistazo
 * quien no puede fichar todavia. Sin el, el problema se descubre delante del
 * quiosco a las 06:00.»*
 *
 * ## Este comando es tambien el que publica las metricas
 *
 * `employees_without_delivered_credential{site}` y `credentials_pending_print{site}`
 * (§8.2) las produce un proceso que corre y termina, asi que se escriben en un
 * fichero para el colector *textfile* de `node-exporter`. El planificador lo
 * ejecuta cada hora con `--quiet`; el endpoint del panel **no** las escribe, para
 * no tocar disco en cada peticion.
 *
 * `--no-metrics` existe para que una consulta manual de madrugada no reescriba el
 * fichero con el alcance de un solo centro: si se pasa `--site`, la publicacion
 * dejaria fuera a los demas y sus series desapareceran. Por eso se apaga sola
 * cuando hay `--site`.
 *
 * ## El nombre completo si sale por aqui
 *
 * Es una consulta que hace una persona de RRHH sobre su propia plantilla, no un
 * log tecnico. La regla dura 21 prohibe nombres en logs y en `error_events`,
 * porque esos viajan al fabricante; esta salida se queda en la terminal de quien
 * la pidio.
 */
final class CredentialStatusCommand extends Command
{
    protected $signature = 'credentials:status
        {--site= : Identificador del centro. Sin el, toda la instalacion}
        {--pending : Solo quien todavia no tiene la tarjeta en la mano}
        {--no-metrics : No reescribe el fichero de metricas}
        {--quiet-table : Solo el resumen, sin la tabla. Para el planificador}';

    protected $description = 'Muestra el estado de las credenciales y publica sus metricas (RF-QR-08).';

    public function handle(CredentialStatusBoard $board): int
    {
        $site = $this->option('site');
        $siteId = \is_string($site) && trim($site) !== '' ? (int) $site : null;

        if ($siteId !== null && $siteId < 1) {
            $this->error('--site tiene que ser el identificador de un centro.');

            return self::INVALID;
        }

        $query = new CredentialStatusQuery(
            siteId: $siteId,
            pendingOnly: (bool) $this->option('pending'),
        );

        // Con `--site` NO se publican metricas aunque no se pida `--no-metrics`:
        // el fichero es global y escribirlo con un solo centro dentro haria
        // desaparecer las series de todos los demas.
        $publish = ! (bool) $this->option('no-metrics') && $siteId === null;

        $report = $publish
            ? $board->handleAndPublishMetrics($query)
            : $board->handle($query);

        if (! (bool) $this->option('quiet-table')) {
            $this->renderRows($report->rows);
        }

        $this->renderCoverage($report->coverage);

        if ($report->pendingPrint() > 0) {
            $this->line('');
            $this->line('Para imprimirlas: php artisan credentials:print-batch --pending --out=/tmp/credenciales.pdf');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<CredentialStatusRow>  $rows
     */
    private function renderRows(array $rows): void
    {
        if ($rows === []) {
            $this->info('Todo el mundo tiene su tarjeta entregada.');

            return;
        }

        $this->table(
            ['Empleado', 'Codigo', 'Centro', 'Departamento', 'Estado', 'Credencial'],
            array_map(static fn (CredentialStatusRow $row): array => [
                $row->employee->fullName,
                $row->employee->employeeCode,
                $row->employee->siteName,
                $row->employee->departmentName ?? '—',
                self::label($row->status),
                $row->credential instanceof Credential ? $row->credential->uuid : '—',
            ], $rows),
        );
    }

    /**
     * @param  list<SiteCredentialCoverage>  $coverage
     */
    private function renderCoverage(array $coverage): void
    {
        foreach ($coverage as $site) {
            $this->line(sprintf(
                '%s — sin tarjeta entregada: %d de %d · pendientes de imprimir: %d',
                $site->siteName,
                $site->withoutDeliveredCredential,
                $site->employees,
                $site->pendingPrint,
            ));
        }
    }

    /**
     * Los textos de la consola van en español porque el operador los lee; el
     * valor que viaja por la API es el del enum, en ingles (doc 02 §3.5).
     */
    private static function label(CredentialLifecycleStatus $status): string
    {
        return match ($status) {
            CredentialLifecycleStatus::NO_CREDENTIAL => 'Sin credencial',
            CredentialLifecycleStatus::PENDING_PRINT => 'Pendiente de imprimir',
            CredentialLifecycleStatus::PENDING_DELIVERY => 'Pendiente de entregar',
            CredentialLifecycleStatus::DELIVERED => 'Entregada',
            CredentialLifecycleStatus::REVOKED => 'Revocada',
        };
    }
}
