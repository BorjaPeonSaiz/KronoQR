<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Query\CredentialStatusQuery;
use App\Modules\Identity\Application\UseCase\CredentialStatusBoard;
use App\Modules\Identity\Application\UseCase\CredentialStatusRow;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Identity\Domain\ValueObject\SiteCredentialCoverage;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
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
 * `--no-metrics` existe para que una consulta manual no reescriba el fichero
 * que alimenta el planificador.
 *
 * ## El nombre completo si sale por aqui
 *
 * Es una consulta que hace una persona de RRHH sobre su propia plantilla, no un
 * log tecnico. La regla dura 21 prohibe nombres en logs y en `error_events`,
 * porque esos viajan al fabricante; esta salida se queda en la terminal de quien
 * la pidio.
 *
 * Y **por eso mismo deja asiento en `audit_log`** (RS-05): sacar el directorio
 * nominal por una terminal es la misma divulgacion que sacarlo por la API. La
 * ejecucion del planificador —`--quiet-table`, que solo imprime el resumen— no
 * lo deja, porque ahi no hay ningun nombre que se lleve nadie.
 */
final class CredentialStatusCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`, compartida por las ordenes `credentials:*`. */
    private const string TEXTS = 'console.credentials';

    protected $signature = 'credentials:status
        {--pending : Solo quien todavia no tiene la tarjeta en la mano}
        {--key-id= : Solo quien sigue fichando con una tarjeta firmada por esa clave (RF-QR-07)}
        {--no-metrics : No reescribe el fichero de metricas}
        {--quiet-table : Solo el resumen, sin la tabla. Para el planificador}';

    protected $description = 'Muestra el estado de las credenciales y publica sus metricas (RF-QR-08).';

    public function handle(CredentialStatusBoard $board): int
    {
        // Una sola verdad para las dos decisiones que dependen de ella: si las
        // filas nominales se pintan y si la lectura deja asiento en `audit_log`.
        // Derivarlas por separado de la misma opcion es como se acaba auditando
        // lo que no se ve o mostrando lo que no consta.
        $quiet = (bool) $this->option('quiet-table');
        $keyId = trim((string) $this->option('key-id'));

        $query = new CredentialStatusQuery(
            pendingOnly: (bool) $this->option('pending'),
            // RS-05: con `--quiet-table` nadie ve un nombre —solo el resumen—,
            // asi que no hay divulgacion que registrar. Sin la opcion,
            // la tabla nominal sale por pantalla y el asiento se escribe.
            unattended: $quiet,
            // «Quien sigue con la clave vieja» durante una rotacion (§5.3). Es
            // la lista que hay que vaciar antes de `credentials:retire-key`.
            keyId: $keyId === '' ? null : $keyId,
        );

        $publish = ! (bool) $this->option('no-metrics');

        try {
            $report = $publish
                ? $board->handleAndPublishMetrics($query)
                : $board->handle($query);
        } catch (InstallationSiteMissing) {
            // Antes del asistente de puesta en marcha no hay centro, y sin centro
            // no hay ninguna credencial que contar. Es un estado de la
            // instalacion, no un fallo: ni excepcion en pantalla ni linea ERROR en
            // el log, y 0 para que la tarea programada no lo cuente como fallida.
            $this->line($this->text('status.no_site'));

            return self::SUCCESS;
        }

        if (! $quiet) {
            $this->renderRows($report->rows, $keyId === '' ? null : $keyId);
        }

        $this->renderCoverage($report->coverage);

        if ($report->pendingPrint() > 0) {
            $this->line('');
            $this->line($this->text('status.print_hint'));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<CredentialStatusRow>  $rows
     */
    private function renderRows(array $rows, ?string $keyId): void
    {
        if ($rows === [] && $keyId !== null) {
            // La frase que autoriza el ultimo paso de una rotacion (§5.3).
            $this->info($this->text('status.key_unused', ['key' => $keyId]));
            $this->line($this->text('status.can_retire', ['key' => $keyId]));

            return;
        }

        if ($rows === []) {
            $this->info($this->text('status.all_delivered'));

            return;
        }

        $this->table(
            $this->texts('status.columns'),
            array_map(fn (CredentialStatusRow $row): array => [
                $row->employee->fullName,
                $row->employee->employeeCode,
                $row->employee->departmentName ?? '—',
                $this->text('status.states.'.$row->status->value),
                $row->credential instanceof Credential ? $row->credential->uuid : '—',
            ], $rows),
        );
    }

    private function renderCoverage(SiteCredentialCoverage $coverage): void
    {
        $this->line($this->text('status.coverage', [
            'site' => $coverage->siteName,
            'without' => $coverage->withoutDeliveredCredential,
            'employees' => $coverage->employees,
            'pending' => $coverage->pendingPrint,
        ]));

        if ($coverage->unknownKeyCards !== []) {
            // Primero y en rojo: es lo unico de esta salida que significa que
            // hay gente que NO PUEDE FICHAR ahora mismo. Lo demas son procesos
            // en marcha.
            foreach ($coverage->unknownKeyIds() as $keyId) {
                $this->error($this->text('status.unknown_key', [
                    'count' => $coverage->unknownKeyCards[$keyId],
                    'key' => $keyId,
                ]));
                $this->paragraph('status.unknown_key_help', ['key' => $keyId]);
            }
        }

        if ($coverage->retiringKeyId !== null) {
            // El avance de la reimpresion, en una linea. Se pinta siempre que
            // haya una rotacion abierta, aunque no se haya filtrado por clave:
            // quien mira el estado tiene que enterarse de que hay una en curso.
            $this->line($this->text('status.rotation', [
                'pending' => $coverage->pendingReprint,
                'employees' => $coverage->employees,
                'key' => $coverage->retiringKeyId,
            ]));
        }
    }
}
