<?php

declare(strict_types=1);

namespace Tests\Support\Workforce;

use App\Modules\Workforce\Application\Command\OffboardEmployeeCommand;
use App\Modules\Workforce\Application\Command\UpdateEmployeeCommand;
use App\Modules\Workforce\Application\UseCase\OffboardEmployeeHandler;
use App\Modules\Workforce\Application\UseCase\UpdateEmployeeHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\Concurrency\ChildSessions;
use Tests\Support\Time\FrozenTime;
use Throwable;

/**
 * Una escritura de la ficha del empleado **en otra sesion de PostgreSQL**, con
 * `lock_timeout` (ADR-046 §5).
 *
 * ## Por que otro proceso y no otra conexion del mismo
 *
 * Lo que se quiere ver es el caso de uso entero —su transaccion, el candado de
 * la cadena de `audit_log`, los listeners de la baja— corriendo en una sesion
 * distinta de la que lo interrumpe. Dentro del mismo proceso eso no se consigue
 * cambiando la conexion por defecto: el escritor de la auditoria y
 * `SerializedLedgerWrite` son `singleton` y se quedan con la conexion que
 * resolvieron, asi que el asiento de la segunda escritura caeria dentro de la
 * transaccion de la primera y el candado de la cadena —reentrante— no esperaria
 * nunca. La prueba pasaria por la razon equivocada.
 *
 * Tampoco vale `ParallelRequests`: bifurca, y para bifurcar hay que cerrar antes
 * las conexiones, lo que revertiria la transaccion que se esta interrumpiendo.
 *
 * Un proceso PHP nuevo arranca la aplicacion de cero con el mismo entorno de
 * pruebas y abre su propia conexion. Cuesta un arranque (menos de un segundo) y
 * a cambio es exactamente lo que ocurre en produccion: dos peticiones, dos
 * procesos de PHP-FPM.
 *
 * ## Determinista sin medir tiempos
 *
 * El proceso padre espera a que el hijo termine. Si el padre tiene la ficha o la
 * cadena tomadas, el hijo no puede avanzar y su `lock_timeout` le devuelve
 * `55P03`: no hay carrera que ganar ni perder. Si no las tiene —el codigo de
 * antes de ADR-046—, el hijo confirma. El valor del `lock_timeout` solo decide
 * cuanto tarda en rendirse, nunca el resultado.
 */
final class EmployeeWriteInOtherSession
{
    /** El hijo confirmo su escritura. */
    public const string COMMITTED = 'committed';

    /** El hijo espero a un candado del padre y se rindio (`55P03`). */
    public const string LOCK_TIMEOUT = 'lock_timeout';

    private const string SPEC_VARIABLE = 'KRONOQR_TEST_EMPLOYEE_WRITE';

    private const string RESULT_MARKER = '@@kronoqr-employee-write@@';

    private const string LOCK_TIMEOUT_SETTING = '1s';

    private ?string $outcome = null;

    /**
     * @param  array<string, string>  $spec
     */
    private function __construct(private readonly array $spec) {}

    /**
     * La baja de la persona, con la fecha de cese indicada.
     */
    public static function offboard(string $employeeUuid, string $terminatedOn, string $frozenAt): self
    {
        return new self([
            'action' => 'offboard',
            'employee' => $employeeUuid,
            'terminated_on' => $terminatedOn,
            'frozen_at' => $frozenAt,
        ]);
    }

    /**
     * Una modificacion de la ficha que solo cambia los apellidos.
     */
    public static function updateLastName(string $employeeUuid, string $lastName, string $frozenAt): self
    {
        return new self([
            'action' => 'update',
            'employee' => $employeeUuid,
            'last_name' => $lastName,
            'frozen_at' => $frozenAt,
        ]);
    }

    /**
     * Ejecuta la escritura en un proceso nuevo y espera a que termine.
     */
    public function run(): void
    {
        $process = new Process(
            [PHP_BINARY, '-r', self::childScript()],
            cwd: base_path(),
            env: [
                self::SPEC_VARIABLE => json_encode($this->spec, JSON_THROW_ON_ERROR),
                // La base de pruebas, explicita: el hijo no lee `phpunit.xml`.
                'APP_ENV' => 'testing',
                'DB_DATABASE' => config()->string('database.connections.'.config()->string('database.default').'.database'),
            ],
            timeout: 60.0,
        );

        $process->run();

        $this->outcome = self::outcomeOf($process);
    }

    /**
     * Que paso en la otra sesion: {@see self::COMMITTED},
     * {@see self::LOCK_TIMEOUT}, `rejected:<excepcion>` si el caso de uso la
     * rechazo, o `null` si nunca llego a ejecutarse.
     */
    public function outcome(): ?string
    {
        return $this->outcome;
    }

    /**
     * El lado del hijo. Lo llama el guion de {@see self::childScript()} con la
     * aplicacion ya arrancada.
     *
     * @return array{outcome: string, detail: string}
     */
    public static function runHere(): array
    {
        /** @var array<string, string> $spec */
        $spec = json_decode((string) getenv(self::SPEC_VARIABLE), true, 512, JSON_THROW_ON_ERROR);

        FrozenTime::at($spec['frozen_at']);
        DB::statement("SET lock_timeout = '".self::LOCK_TIMEOUT_SETTING."'");

        try {
            match ($spec['action']) {
                'offboard' => app(OffboardEmployeeHandler::class)->handle(new OffboardEmployeeCommand(
                    uuid: $spec['employee'],
                    terminatedAt: $spec['terminated_on'],
                    reason: 'Baja registrada desde otra sesion',
                )),
                'update' => app(UpdateEmployeeHandler::class)->handle(new UpdateEmployeeCommand(
                    uuid: $spec['employee'],
                    lastName: $spec['last_name'],
                )),
                default => throw new RuntimeException('Escritura desconocida: '.$spec['action']),
            };

            return ['outcome' => self::COMMITTED, 'detail' => ''];
        } catch (QueryException $failure) {
            return (string) $failure->getCode() === '55P03'
                ? ['outcome' => self::LOCK_TIMEOUT, 'detail' => '']
                : ['outcome' => 'query_error', 'detail' => (string) $failure->getCode()];
        } catch (Throwable $failure) {
            return ['outcome' => 'rejected:'.$failure::class, 'detail' => ''];
        }
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
                'La escritura en la otra sesion no devolvio resultado (codigo '.$process->getExitCode().'): '
                .$process->getErrorOutput().$output,
            );
        }

        try {
            /** @var array{outcome: string, detail: string} $result */
            $result = json_decode(substr($output, $position + \strlen(self::RESULT_MARKER)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('Resultado ilegible de la otra sesion: '.$output, 0, $failure);
        }

        return $result['detail'] === '' ? $result['outcome'] : $result['outcome'].':'.$result['detail'];
    }
}
