<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\Command\RetentionRunCommand;
use App\Modules\Compliance\Application\Exception\AuditPartitionNotPurgeable;
use App\Modules\Compliance\Application\Exception\RetentionNotConfirmed;
use App\Modules\Compliance\Application\Exception\RetentionResponsibleNotActive;
use App\Modules\Compliance\Application\UseCase\ApplyRetention;
use App\Modules\Compliance\Application\UseCase\RetentionOutcome;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseWorkRecordArchive;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Infrastructure\Console\ConsoleIds;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;

/**
 * `php artisan compliance:apply-retention` — propone la purga por retencion y,
 * solo con confirmacion, la ejecuta (RL-02, RL-11, RF-PR-03, doc 02 Anexo C).
 *
 * ## Por defecto NO borra
 *
 * Sin `--confirm`, el comando simula: escribe el informe, lo imprime y termina.
 * Es lo que ejecuta el planificador cada semana. La purga real exige la frase que
 * ese informe imprime, que depende del corte y del perfil de cumplimiento: no se
 * puede teclear de memoria ni copiar de un runbook, hay que haber leido lo que se
 * va a llevar. Es la traduccion literal de RF-PR-03 -«el sistema propone y exige
 * confirmacion del responsable»- y de la regla dura 5: esta es la unica
 * eliminacion legitima de datos del producto.
 *
 * ## Dos credenciales, y solo la segunda hace falta para borrar
 *
 * La simulacion corre con el rol de la aplicacion: solo cuenta. La ejecucion
 * necesita ademas la conexion `pgsql_maintenance`, cuya credencial **no vive en
 * el `.env` de la aplicacion** (ADR-033) y la aporta quien ejecuta la purga. Si
 * falta, el comando lo dice y no borra nada: ver
 * `docs/runbooks/solicitud-derechos-rgpd.md` §5 y `docs/cliente/operacion.md`.
 *
 * ## Codigos de salida
 *
 * `0` si la pasada termino -haya purgado o no-, `1` si algo la aborto: una
 * cadena de auditoria que no verifica, una frase que no corresponde o la falta de
 * centro de trabajo. Un fallo aqui nunca deja el sistema a medias: lo destructivo
 * va detras de todas las comprobaciones.
 * `2` si `--responsible` no es un id entero positivo o nombra una cuenta que no
 * existe o no esta activa, tanto al simular como al ejecutar: no se simula ni se
 * borra nada.
 */
final class ApplyRetentionCommand extends Command
{
    protected $signature = 'compliance:apply-retention
        {--dry-run : Simula y no borra nada. Es el modo por defecto}
        {--confirm= : Frase que imprime la simulacion. Sin ella NO se borra nada}
        {--responsible= : Id de la cuenta de gestion activa que autoriza la purga, para el asiento}
        {--batch= : Filas por sentencia de borrado. Por defecto compliance.retention.batch_size}';

    protected $description = 'Propone la purga por retencion y, con confirmacion explicita, la ejecuta (RF-PR-03)';

    public function handle(ApplyRetention $retention): int
    {
        $confirmation = $this->stringOption('confirm');
        $batchSize = $this->batchSize();

        // `--dry-run` MANDA sobre `--confirm`: si alguien escribe los dos, lo que
        // ha pedido es simular. Ante dos senales contrarias, la que no borra.
        $simulate = $confirmation === '' || $this->option('dry-run') === true;

        // `--responsible` firma el asiento: `12abc` no es la cuenta 12, es un
        // error de quien teclea. Sale con 2 sin simular ni borrar nada.
        $responsibleOption = $this->stringOption('responsible');
        $responsible = $responsibleOption === '' ? null : ConsoleIds::positive($responsibleOption);

        if ($responsibleOption !== '' && $responsible === null) {
            $this->error($this->text('retention.invalid_responsible', ['value' => $responsibleOption]));

            return self::INVALID;
        }

        $order = $simulate
            ? RetentionRunCommand::simulate($batchSize, $responsible)
            : RetentionRunCommand::execute($confirmation, $responsible, $batchSize);

        try {
            $outcome = $retention->handle($order);
        } catch (RetentionResponsibleNotActive $notActive) {
            $this->error($this->text('retention.responsible_not_active', ['id' => (string) $notActive->userId]));

            return self::INVALID;
        } catch (RetentionNotConfirmed|AuditPartitionNotPurgeable|InstallationSiteMissing $stopped) {
            $this->error($stopped->getMessage());

            return self::FAILURE;
        } catch (QueryException $failed) {
            return $this->explainDatabaseFailure($failed);
        }

        $this->render($outcome);

        return self::SUCCESS;
    }

    private function render(RetentionOutcome $outcome): void
    {
        $report = $outcome->report;

        $this->info($report->mode->label());
        $this->line('Corte del registro de jornada: anterior a '.$report->workRecordCutoff->format('Y-m-d')
            .' ('.$report->policy->legalRecordYears.' anos, perfil del centro '.$report->policy->siteId.').');

        foreach (RetentionScope::cases() as $scope) {
            $tallies = $report->talliesFor($scope);

            if ($tallies === []) {
                continue;
            }

            $this->line('');
            $this->line($scope->label().':');
            $this->table(
                ['Almacen', 'Cantidad', 'Rango'],
                array_map(
                    static fn (RetentionTally $tally): array => [
                        $tally->dataset,
                        (string) $tally->rows,
                        $tally->range(),
                    ],
                    $tallies,
                ),
            );
        }

        foreach ($report->notes as $note) {
            $this->warn($note);
        }

        $this->line('');
        $this->line('Informe: '.$outcome->reportPath);

        if (! $report->mode->isSimulation()) {
            $this->info('Purgados '.$report->totalRows().' registros. Queda asiento en audit_log.');

            return;
        }

        if ($report->isEmpty()) {
            $this->info('No hay nada vencido: no se purgaria nada.');

            return;
        }

        $this->line('');
        $this->warn('Nada se ha borrado. Para ejecutar ESTA purga, con la autorizacion del responsable:');
        $this->line('  php artisan compliance:apply-retention --confirm='.$outcome->confirmationToken()
            .' --responsible=<id>');
    }

    /**
     * Lo que hay que hacer, segun **por que** fallo (ADR-016: no podemos entrar
     * a arreglarlo). Toda la purga va en la transaccion del asiento, asi que
     * cualquier fallo de aqui no ha borrado nada.
     *
     * **Por SQLSTATE, no por el texto.** El mensaje de `QueryException` incluye
     * la sentencia entera, que nombra siempre la funcion de purga: buscar su
     * nombre en el mensaje seria verdadero en cualquier fallo de la purga, y el
     * operador recibiria «revisa los relojes» ante un bloqueo o un perfil que
     * falta.
     *
     * - `42501` con el texto del rechazo del corte: la funcion de ADR-057 §3
     *   calcula su corte con el reloj de PostgreSQL y el plazo **mas largo de
     *   todos los centros**. Otro `42501` (un rol sin `EXECUTE`) es generico.
     * - `55P03`: `lock_timeout` de la funcion; se puede repetir.
     * - `P0002`: no hay perfil de cumplimiento.
     */
    private function explainDatabaseFailure(QueryException $failed): int
    {
        $state = (string) $failed->getCode();

        $reason = match (true) {
            $state === '42501' && str_contains($failed->getMessage(), DatabaseWorkRecordArchive::CUTOFF_REJECTED) => 'cutoff',
            $state === '55P03' => 'lock',
            $state === 'P0002' => 'profile',
            default => 'generic',
        };

        $this->error($this->text('retention.failure.title'));

        foreach ($this->lines('retention.failure.'.$reason) as $line) {
            $this->line($line);
        }

        $this->line($this->text('retention.failure.detail', ['detail' => $failed->getMessage()]));

        return self::FAILURE;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return \is_string($text) ? $text : $key;
    }

    /**
     * @return list<string>
     */
    private function lines(string $key): array
    {
        $lines = __($key);

        return \is_array($lines) ? array_values(array_filter($lines, \is_string(...))) : [$key];
    }

    private function batchSize(): int
    {
        $batch = (int) $this->stringOption('batch');

        return $batch > 0 ? $batch : Config::integer('compliance.retention.batch_size', 1000);
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return \is_string($value) ? trim($value) : '';
    }
}
