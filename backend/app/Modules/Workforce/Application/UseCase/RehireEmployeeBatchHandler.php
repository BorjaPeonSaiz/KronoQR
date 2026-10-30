<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Workforce\Application\Command\RehireEmployeeCommand;
use App\Modules\Workforce\Application\Command\RehireEmployeesCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\PinMaterial;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeesRehired;
use App\Modules\Workforce\Domain\Exception\EmployeeNotTerminated;
use App\Modules\Workforce\Domain\Exception\InvalidRehireDate;
use App\Modules\Workforce\Domain\Exception\EmployeeRecordMinimised;
use App\Modules\Workforce\Domain\Exception\RehireBatchRejected;
use App\Modules\Workforce\Domain\Model\Employee;
use DateTimeImmutable;

/**
 * Readmision de varias personas con una fecha de alta comun (RN-27, ADR-062 §6):
 * la vuelta de la temporada.
 *
 * **Todo o nada, y se decide antes de escribir.** Primero se toman todas las
 * fichas, en orden de `id` para que dos lotes que comparten personas no se
 * crucen, y se comprueba cada una con el dominio. Si alguna no se puede
 * readmitir, {@see RehireBatchRejected} lleva un error por posicion y no se ha
 * escrito nada. Si todas pueden, cada una pasa por la misma readmision que la
 * individual ({@see RehireEmployeeHandler::rehireLocked()}), con su asiento y su
 * tarjeta nueva. El uso del plan se cuenta una vez, con {@see EmployeesRehired}.
 *
 * Los PIN nuevos, si se piden, se calculan antes de abrir nada (ADR-046 A-3).
 */
final readonly class RehireEmployeeBatchHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private RehireEmployeeHandler $rehire,
        private WorkforceEventPublisher $events,
        private EmployeeWriteRetry $retry,
        private SerializedLedgerWrite $serialized,
        private Clock $clock,
    ) {}

    /**
     * @return list<RehiredEmployee> en el orden de la peticion
     *
     * @throws RehireBatchRejected
     */
    public function handle(RehireEmployeesCommand $command): array
    {
        return UseCaseInstrumentation::run('workforce.rehire_employees', fn (): array => $this->doHandle($command));
    }

    /**
     * @return list<RehiredEmployee>
     */
    private function doHandle(RehireEmployeesCommand $command): array
    {
        $commands = array_map(fn (string $uuid): RehireEmployeeCommand => new RehireEmployeeCommand(
            uuid: $uuid,
            hiredAt: $command->hiredAt,
            actorUserId: $command->actorUserId,
            resetPin: $command->resetPin,
            countedByBatch: true,
        ), $command->uuids);

        $materials = array_map(fn (RehireEmployeeCommand $one): ?PinMaterial => $this->rehire->pinMaterialFor($one), $commands);

        return $this->retry->run('employee.rehire_batch', fn (): array => $this->serialized->withChainLock(function () use ($commands, $materials): array {
            $locked = $this->employees->findManyForUpdate(array_map(static fn (RehireEmployeeCommand $one): string => $one->uuid, $commands));

            $this->assertAllRehirable($commands, $locked);

            $rehired = [];

            foreach ($commands as $position => $one) {
                $rehired[] = $this->rehire->rehireLocked($locked[$one->uuid], $one, $materials[$position]);
            }

            $this->events->publish(new EmployeesRehired(\count($rehired), $this->clock->now()));

            return $rehired;
        }));
    }

    /**
     * @param  list<RehireEmployeeCommand>  $commands
     * @param  array<string, Employee>  $locked
     *
     * @throws RehireBatchRejected
     */
    private function assertAllRehirable(array $commands, array $locked): void
    {
        $errors = [];

        foreach ($commands as $position => $one) {
            $error = self::errorFor($locked[$one->uuid] ?? null, $one);

            if ($error !== null) {
                $errors[$position] = $error;
            }
        }

        if ($errors !== []) {
            throw RehireBatchRejected::at($errors);
        }
    }

    /**
     * @return array{key: string, parameters: array<string, string>, message: string}|null
     */
    private static function errorFor(?Employee $employee, RehireEmployeeCommand $command): ?array
    {
        if (! $employee instanceof Employee) {
            return ['key' => RehireBatchRejected::NOT_FOUND, 'parameters' => [], 'message' => 'not found'];
        }

        try {
            $employee->rehire(new DateTimeImmutable($command->hiredAt));
        } catch (EmployeeNotTerminated $exception) {
            return ['key' => EmployeeNotTerminated::TRANSLATION_KEY, 'parameters' => [], 'message' => $exception->getMessage()];
        } catch (EmployeeRecordMinimised $exception) {
            return ['key' => EmployeeRecordMinimised::TRANSLATION_KEY, 'parameters' => $exception->parameters, 'message' => $exception->getMessage()];
        } catch (InvalidRehireDate $exception) {
            return ['key' => $exception->translationKey, 'parameters' => $exception->parameters, 'message' => $exception->getMessage()];
        }

        return null;
    }
}
