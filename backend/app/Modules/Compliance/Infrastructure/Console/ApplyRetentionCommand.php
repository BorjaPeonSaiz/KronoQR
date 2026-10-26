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
use App\Modules\Compliance\Infrastructure\Retention\RetentionExecutionCommand;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Infrastructure\Console\ConsoleResponsible;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
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
 * cadena de auditoria que no verifica, una frase que no corresponde, la falta de
 * centro de trabajo o una confirmacion de la firma respondida que no. Un fallo
 * aqui nunca deja el sistema a medias: lo destructivo va detras de todas las
 * comprobaciones.
 * `2` si falta `--responsible` al ejecutar, si no es el `uuid` ni el correo de
 * una cuenta de gestion activa con rol admin o rrhh (un id numerico se rechaza
 * desde la 2.3.0), tanto al simular como al ejecutar, o si sin terminal falta o
 * no cuadra `--responsible-confirm`: no se simula ni se borra nada.
 *
 * ## Quien firma
 *
 * Ejecutar exige `--responsible` y, antes de borrar, enseña la cuenta que firma
 * y pide confirmarla ({@see ConsoleResponsible}; doc 01 RF-PR-03 y §7.2). La
 * simulacion semanal del planificador no lo necesita: no escribe asiento.
 */
final class ApplyRetentionCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Fichero de `lang/` de la purga: lo comparten esta orden y su informe. */
    private const string TEXTS = 'retention';

    protected $signature = 'compliance:apply-retention
        {--dry-run : Simula y no borra nada. Es el modo por defecto}
        {--confirm= : Frase que imprime la simulacion. Sin ella NO se borra nada}
        {--responsible= : Uuid o correo de la cuenta de gestion activa (admin o rrhh) que autoriza la purga y firma el asiento. Obligatorio con --confirm}
        {--responsible-confirm= : Correo de la cuenta de --responsible. Obligatorio sin terminal}
        {--batch= : Filas por sentencia de borrado. Por defecto compliance.retention.batch_size}';

    protected $description = 'Propone la purga por retencion y, con confirmacion explicita, la ejecuta (RF-PR-03)';

    public function handle(ApplyRetention $retention, ResponsibleAccounts $accounts): int
    {
        $confirmation = $this->stringOption('confirm');
        $batchSize = $this->batchSize();

        // `--dry-run` MANDA sobre `--confirm`: si alguien escribe los dos, lo que
        // ha pedido es simular. Ante dos senales contrarias, la que no borra.
        $simulate = $confirmation === '' || $this->option('dry-run') === true;

        $responsible = $this->responsible($accounts, $simulate);

        if (\is_int($responsible)) {
            return $responsible;
        }

        $order = $simulate || ! $responsible instanceof ResponsibleAccount
            ? RetentionRunCommand::simulate($batchSize, $responsible?->id)
            : $this->signedExecution($confirmation, $responsible, $batchSize);

        if (\is_int($order)) {
            return $order;
        }

        try {
            $outcome = $retention->handle($order);
        } catch (RetentionResponsibleNotActive) {
            $this->error($this->text('responsible_not_active'));

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

    /**
     * La cuenta que autoriza la purga, `null` si se simula sin ella, o el
     * codigo de salida (`2`) si falta para ejecutar o no puede firmar: no se
     * simula ni se borra nada. En la simulacion es opcional y, si se da, se
     * comprueba igual: una simulacion con una cuenta que no vale no sirve de
     * ensayo de nada.
     */
    private function responsible(ResponsibleAccounts $accounts, bool $simulate): ResponsibleAccount|int|null
    {
        $option = $this->stringOption('responsible');

        if ($option === '') {
            return $simulate ? null : ConsoleResponsible::missing($this);
        }

        return ConsoleResponsible::resolve($this, $accounts, $option, ApplyRetention::RESPONSIBLE_ROLES);
    }

    /**
     * La ejecucion, despues de enseñar quien la firma y de que lo confirme: con
     * terminal se pregunta; sin ella, `--responsible-confirm` con su correo.
     */
    private function signedExecution(string $confirmation, ResponsibleAccount $responsible, int $batchSize): RetentionRunCommand|int
    {
        $declined = ConsoleResponsible::confirm(
            $this,
            $responsible,
            $this->text('confirm_signature'),
            ! $this->input->isInteractive(),
        );

        return $declined ?? RetentionRunCommand::execute($confirmation, $responsible->id, $batchSize);
    }

    private function render(RetentionOutcome $outcome): void
    {
        $report = $outcome->report;

        $this->info($this->text('report.modes.'.$report->mode->value));
        $this->line($this->text('console.cutoff', [
            'date' => $report->workRecordCutoff->format('Y-m-d'),
            'years' => $report->policy->legalRecordYears,
            'site' => $report->policy->siteId,
        ]));

        foreach (RetentionScope::cases() as $scope) {
            $tallies = $report->talliesFor($scope);

            if ($tallies === []) {
                continue;
            }

            $this->line('');
            $this->line($this->text('report.scopes.'.$scope->value).':');
            $this->table(
                $this->texts('console.columns'),
                array_map(
                    fn (RetentionTally $tally): array => [
                        $tally->dataset,
                        (string) $tally->rows,
                        $tally->available ? $tally->range() : $this->text('report.not_installed'),
                    ],
                    $tallies,
                ),
            );
        }

        foreach ($report->notes as $note) {
            $this->warn($note);
        }

        $this->line('');
        $this->line($this->text('console.report', ['path' => $outcome->reportPath]));

        if (! $report->mode->isSimulation()) {
            $this->info($this->text('console.purged', ['count' => $report->totalRows()]));

            return;
        }

        if ($report->isEmpty()) {
            $this->info($this->text('console.nothing_due'));

            return;
        }

        $this->line('');
        $this->warn($this->text('console.how_to_execute'));
        // La misma orden que imprime el informe: sale de un solo sitio.
        $this->line('  '.RetentionExecutionCommand::for($report));
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

        $this->error($this->text('failure.title'));
        $this->paragraph('failure.'.$reason);
        $this->line($this->text('failure.detail', ['detail' => $failed->getMessage()]));

        return self::FAILURE;
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
