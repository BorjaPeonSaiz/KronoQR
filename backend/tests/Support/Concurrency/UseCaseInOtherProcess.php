<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use App\Modules\Identity\Application\Command\DeactivateManagementAccountCommand;
use App\Modules\Identity\Application\Command\DeliverCredentialCommand;
use App\Modules\Identity\Application\Command\IssueCredentialCommand;
use App\Modules\Identity\Application\Command\PrintCredentialCommand;
use App\Modules\Identity\Application\Command\ResetManagementPasswordCommand;
use App\Modules\Identity\Application\Command\ResetManagementTwoFactorCommand;
use App\Modules\Identity\Application\Command\RevokeCredentialCommand;
use App\Modules\Identity\Application\Command\RotateSigningKeyCommand;
use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Application\UseCase\DeactivateManagementAccountHandler;
use App\Modules\Identity\Application\UseCase\DeliverCredential;
use App\Modules\Identity\Application\UseCase\IssueCredential;
use App\Modules\Identity\Application\UseCase\PrintCredential;
use App\Modules\Identity\Application\UseCase\ResetManagementPasswordHandler;
use App\Modules\Identity\Application\UseCase\ResetTwoFactorHandler;
use App\Modules\Identity\Application\UseCase\RevokeCredential;
use App\Modules\Identity\Application\UseCase\RotateSigningKey;
use App\Modules\Shared\Infrastructure\Persistence\AuditChainLock;
use App\Modules\Workforce\Application\Command\ImportEmployeesCommand;
use App\Modules\Workforce\Application\Command\OffboardEmployeeCommand;
use App\Modules\Workforce\Application\Command\RecordPinDeliveryCommand;
use App\Modules\Workforce\Application\Command\RegisterEmployeeCommand;
use App\Modules\Workforce\Application\Command\ResetEmployeePinCommand;
use App\Modules\Workforce\Application\Command\UpdateDepartmentCommand;
use App\Modules\Workforce\Application\Command\UpdateEmployeeCommand;
use App\Modules\Workforce\Application\UseCase\ImportEmployeesHandler;
use App\Modules\Workforce\Application\UseCase\OffboardEmployeeHandler;
use App\Modules\Workforce\Application\UseCase\RecordPinDeliveryHandler;
use App\Modules\Workforce\Application\UseCase\RegisterEmployeeHandler;
use App\Modules\Workforce\Application\UseCase\ResetEmployeePinHandler;
use App\Modules\Workforce\Application\UseCase\UpdateDepartmentHandler;
use App\Modules\Workforce\Application\UseCase\UpdateEmployeeHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeeWriteInOtherSession;
use Throwable;

/**
 * Un caso de uso de verdad, **en otro proceso PHP y sin esperar a que acabe**
 * (ADR-046 §6, puntos 3 y 5).
 *
 * Existe para las pruebas de orden de candados: la prueba toma la cadena de
 * `audit_log` con una conexion propia, arranca aqui el caso de uso —que se
 * queda esperando la cadena—, y mientras espera pregunta desde una tercera
 * sesion si la ficha o la tarjeta estan libres. Eso no cabe en un solo proceso:
 * quien espera un candado no puede, a la vez, preguntar por otro.
 *
 * Por que un proceso nuevo y no `ParallelRequests`: bifurcar exige cerrar antes
 * todas las conexiones, y con ellas la transaccion que tiene la cadena. Por que
 * no otra conexion del mismo proceso: el escritor de auditoria y
 * `SerializedLedgerWrite` son `singleton` y se quedan con la conexion que
 * resolvieron (ver {@see EmployeeWriteInOtherSession}).
 *
 * El hijo arranca la aplicacion de cero con el entorno de la prueba, congela el
 * reloj en el mismo instante y responde con lo que paso: {@see self::COMMITTED},
 * `sqlstate:<codigo>` si la base lo corto, o `rejected:<excepcion>` si el caso
 * de uso lo rechazo.
 */
final class UseCaseInOtherProcess
{
    public const string COMMITTED = 'committed';

    private const string SPEC_VARIABLE = 'KRONOQR_TEST_USE_CASE';

    private const string RESULT_MARKER = '@@kronoqr-use-case@@';

    private ?Process $process = null;

    /**
     * @param  array<string, string>  $spec
     */
    private function __construct(private readonly array $spec) {}

    /**
     * @param  array<string, string>  $arguments  Los del caso de uso, por nombre (ver {@see self::runHere()}).
     */
    public static function of(string $action, array $arguments, string $frozenAt): self
    {
        return new self(['action' => $action, 'frozen_at' => $frozenAt, ...$arguments]);
    }

    /**
     * Arranca el proceso y vuelve en el acto.
     */
    public function start(): self
    {
        $this->process = new Process(
            [PHP_BINARY, '-r', self::childScript()],
            cwd: base_path(),
            env: [
                self::SPEC_VARIABLE => json_encode($this->spec, JSON_THROW_ON_ERROR),
                'APP_ENV' => 'testing',
                'DB_DATABASE' => config()->string('database.connections.'.config()->string('database.default').'.database'),
            ],
            timeout: 60.0,
        );

        $this->process->start();

        return $this;
    }

    public function isRunning(): bool
    {
        return $this->process?->isRunning() ?? false;
    }

    /**
     * Espera a que termine y devuelve lo que paso.
     */
    public function finish(): string
    {
        if (! $this->process instanceof Process) {
            throw new RuntimeException('El caso de uso en otro proceso no se ha arrancado.');
        }

        $this->process->wait();

        return self::outcomeOf($this->process);
    }

    /**
     * El lado del hijo, con la aplicacion ya arrancada.
     *
     * @return array{outcome: string}
     */
    public static function runHere(): array
    {
        /** @var array<string, string> $spec */
        $spec = json_decode((string) getenv(self::SPEC_VARIABLE), true, 512, JSON_THROW_ON_ERROR);

        FrozenTime::at($spec['frozen_at']);
        // La impresion dibuja el PDF con Chromium; aqui lo que importa es la
        // escritura de la tarjeta, no el dibujo.
        app()->instance(CardRenderer::class, new FakeCardRenderer);

        try {
            self::dispatch($spec);

            return ['outcome' => self::COMMITTED];
        } catch (QueryException $failure) {
            return ['outcome' => 'sqlstate:'.(string) $failure->getCode()];
        } catch (Throwable $failure) {
            return ['outcome' => 'rejected:'.$failure::class];
        }
    }

    /**
     * @param  array<string, string>  $spec
     */
    private static function dispatch(array $spec): void
    {
        $useCase = self::useCases($spec)[$spec['action']] ?? throw new RuntimeException('Caso de uso desconocido: '.$spec['action']);

        $useCase();
    }

    /**
     * Los casos de uso que sabe ejecutar, por nombre, sin ejecutar ninguno.
     *
     * @param  array<string, string>  $spec
     * @return array<string, callable(): mixed>
     */
    private static function useCases(array $spec): array
    {
        return [
            'update_last_name' => static fn (): mixed => app(UpdateEmployeeHandler::class)->handle(new UpdateEmployeeCommand(
                uuid: $spec['employee'],
                lastName: $spec['last_name'],
            )),
            'move_department' => static fn (): mixed => app(UpdateEmployeeHandler::class)->handle(new UpdateEmployeeCommand(
                uuid: $spec['employee'],
                departmentId: (int) $spec['department'],
                departmentGiven: true,
            )),
            'offboard' => static fn (): mixed => app(OffboardEmployeeHandler::class)->handle(new OffboardEmployeeCommand(
                uuid: $spec['employee'],
                terminatedAt: $spec['terminated_on'],
            )),
            'import' => static fn (): mixed => app(ImportEmployeesHandler::class)->handle(
                new ImportEmployeesCommand(path: $spec['path'], apply: true, confirmChecksum: $spec['checksum']),
                500,
                self::columnAliases(),
            ),
            'reset_pin' => static fn (): mixed => app(ResetEmployeePinHandler::class)->handle(new ResetEmployeePinCommand($spec['employee'])),
            'deliver_pin' => static fn (): mixed => app(RecordPinDeliveryHandler::class)->handle(new RecordPinDeliveryCommand(
                employeeUuid: $spec['employee'],
                deliveredByUserUuid: $spec['user'],
            )),
            'issue_credential' => static fn (): mixed => app(IssueCredential::class)->handle(new IssueCredentialCommand($spec['employee'])),
            'rotate_signing_key' => static fn (): mixed => app(RotateSigningKey::class)->handle(new RotateSigningKeyCommand),
            'revoke_credential' => static fn (): mixed => app(RevokeCredential::class)->handle(new RevokeCredentialCommand(
                credentialUuid: $spec['credential'],
                reason: 'Tarjeta perdida',
            )),
            'deliver_credential' => static fn (): mixed => app(DeliverCredential::class)->handle(new DeliverCredentialCommand(
                credentialUuid: $spec['credential'],
                deliveredByUserId: (int) $spec['user'],
            )),
            'print_credential' => static fn (): mixed => app(PrintCredential::class)->handle(
                PrintCredentialCommand::forCredential($spec['credential']),
            ),
            // Cuentas de gestion (RF-ID-10, ADR-051 §6), por consola: sin
            // quien actua ni reautenticacion, que no toman candados.
            'deactivate_management_account' => static fn (): mixed => app(DeactivateManagementAccountHandler::class)->handle(
                new DeactivateManagementAccountCommand(accountUuid: $spec['account'], reason: 'Orden de candados'),
            ),
            'reset_management_password' => static fn (): mixed => app(ResetManagementPasswordHandler::class)->handle(
                new ResetManagementPasswordCommand(accountUuid: $spec['account'], reason: 'Orden de candados'),
            ),
            'reset_management_two_factor' => static fn (): mixed => app(ResetTwoFactorHandler::class)->handle(
                new ResetManagementTwoFactorCommand(accountUuid: $spec['account'], reason: 'Orden de candados'),
            ),
            'assign_department_manager' => static fn (): mixed => app(UpdateDepartmentHandler::class)->handle(new UpdateDepartmentCommand(
                id: (int) $spec['department'],
                managerGiven: true,
                managerUserUuid: $spec['manager'],
            )),
            'rename_department' => static fn (): mixed => app(UpdateDepartmentHandler::class)->handle(new UpdateDepartmentCommand(
                id: (int) $spec['department'],
                name: $spec['name'],
            )),
            // Dentro de una transaccion de fuera A PROPOSITO: asi
            // `EmployeeWriteRetry` no reintenta un `40P01` y el abrazo se ve
            // como `sqlstate:40P01` en lugar de esconderse tras un reintento
            // que por HTTP no siempre le toca a esta parte.
            'register_employee_in_transaction' => static fn (): mixed => DB::transaction(
                static fn (): mixed => app(RegisterEmployeeHandler::class)->handle(new RegisterEmployeeCommand(
                    departmentId: (int) $spec['department'],
                    firstName: 'Persona',
                    lastName: 'De la carrera',
                    email: null,
                    nationalId: null,
                    hiredAt: '2026-10-01',
                    locale: 'es',
                )),
            ),
            // El orden PROHIBIDO, a mano: la ficha primero y la cadena despues.
            // Es el control de las pruebas de orden de candados, lo que demuestra
            // que pueden fallar.
            'control_row_then_chain' => static fn (): bool => DB::transaction(static function () use ($spec): bool {
                DB::select('SELECT id FROM employees WHERE uuid = ? FOR NO KEY UPDATE', [$spec['employee']]);
                AuditChainLock::takeOn(DB::connection());

                return true;
            }),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function columnAliases(): array
    {
        /** @var array<string, list<string>> $aliases */
        $aliases = config()->array('workforce.import.column_aliases');

        return $aliases;
    }

    private static function childScript(): string
    {
        return 'require "vendor/autoload.php";'
            .'$app = require "bootstrap/app.php";'
            .'$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .'fwrite(STDOUT, "\n'.self::RESULT_MARKER.'" . json_encode('.self::class.'::runHere(), JSON_THROW_ON_ERROR));'
            // La sesion se cierra explicitamente antes de salir (bloque 17):
            // ver `ChildSessions`.
            .ChildSessions::class.'::closeAll();';
    }

    private static function outcomeOf(Process $process): string
    {
        $output = $process->getOutput();
        $position = strrpos($output, self::RESULT_MARKER);

        if (! $process->isSuccessful() || $position === false) {
            throw new RuntimeException(
                'El caso de uso en otro proceso no devolvio resultado (codigo '.$process->getExitCode().'): '
                .$process->getErrorOutput().$output,
            );
        }

        try {
            /** @var array{outcome: string} $result */
            $result = json_decode(substr($output, $position + \strlen(self::RESULT_MARKER)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('Resultado ilegible del otro proceso: '.$output, 0, $failure);
        }

        return $result['outcome'];
    }
}
