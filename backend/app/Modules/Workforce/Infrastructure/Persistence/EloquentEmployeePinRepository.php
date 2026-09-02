<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Workforce\Application\Port\EmployeePinRepository;
use App\Modules\Workforce\Application\Port\PinDeliveryRecord;
use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Domain\Exception\PinAlreadyDelivered;
use App\Modules\Workforce\Domain\Exception\PinNotIssued;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * El PIN sobre Eloquent (RF-ID-09).
 *
 * **Hash y nunca el PIN, y desde la revision de la 5.5 el PIN en claro ni
 * siquiera entra aqui.** El hash lo calcula el caso de uso con el algoritmo
 * `.env` —bcrypt o argon2id—, el mismo de las contrasenas de gestion: no hay una
 * segunda decision criptografica que mantener. Ninguna consulta de esta clase
 * devuelve `pin_hash`, y el modelo lo tiene en `$hidden` para que no salga por
 * un `toArray()` de depuracion.
 *
 * **Emitir borra la entrega anterior.** Un PIN nuevo no esta entregado porque lo
 * estuviera el que sustituye: dejar la fecha antigua haria que el panel diera
 * por entregado un PIN que nadie ha recibido, y ese es justo el estado que
 * RF-ID-09 quiere hacer visible.
 *
 * **La entrega se registra con una escritura condicional.** El `UPDATE` lleva en
 * su `WHERE` que haya PIN y que no conste ya entregado, de modo que dos clics
 * simultaneos en el panel no producen dos entregas: el segundo no afecta a
 * ninguna fila y se traduce en el conflicto que corresponde. Comprobarlo antes
 * con un `SELECT` seria una condicion de carrera con aspecto de comprobacion,
 * igual que en el resto de este modulo.
 *
 * **Quien entrego se resuelve por la TABLA `users`, no por su modelo.** Es la
 * misma decision que toma `Compliance\Infrastructure\Audit\CurrentAuditContext`
 * y por el mismo motivo: `Workforce` no puede importar nada de `Identity` (doc
 * 02 §1.6, verificado por Deptrac), y tampoco deberia — la clase puede cambiar
 * de nombre, la tabla no, porque `employees.pin_delivered_by_user_id` es una
 * clave ajena hacia ella. Hacia arriba solo viaja el UUID publico.
 */
final readonly class EloquentEmployeePinRepository implements EmployeePinRepository
{
    /**
     * Escribe el hash **ya calculado**.
     *
     * Al contrario que hasta la revision de la 5.5, aqui ya no se llama a
     * `Hash::make()`: el calculo lo decide el caso de uso, porque tiene que poder
     * ocurrir FUERA de una transaccion larga. bcrypt cuesta unos 160 ms por PIN
     * y 500 de ellos dentro de la transaccion de una importacion monopolizaban el
     * candado global de `audit_log`, y con el los fichajes del hotel.
     *
     * **El PIN en claro ya no entra en este metodo**, que es una garantia mas
     * fuerte que la anterior: no hay ninguna via por la que pueda acabar en el
     * texto de una consulta.
     */
    public function issue(string $employeeUuid, string $pinHash, DateTimeImmutable $issuedAt): bool
    {
        $affected = Employee::query()
            ->where('uuid', $employeeUuid)
            ->update([
                'pin_hash' => $pinHash,
                'pin_issued_at' => $issuedAt,
                'pin_delivered_at' => null,
                'pin_delivered_by_user_id' => null,
            ]);

        return $affected > 0;
    }

    public function recordDelivery(
        string $employeeUuid,
        string $deliveredByUserUuid,
        DateTimeImmutable $deliveredAt,
    ): ?PinDeliveryRecord {
        if (! Employee::query()->where('uuid', $employeeUuid)->exists()) {
            return null;
        }

        $affected = Employee::query()
            ->where('uuid', $employeeUuid)
            ->whereNotNull('pin_issued_at')
            ->whereNull('pin_delivered_at')
            ->update([
                'pin_delivered_at' => $deliveredAt,
                'pin_delivered_by_user_id' => $this->internalIdOf($deliveredByUserUuid),
            ]);

        if ($affected === 0) {
            // No hubo fila que actualizar: o no hay PIN, o ya constaba
            // entregado. Se relee para decir cual de las dos cosas fue, que es
            // lo unico que distingue los dos conflictos.
            throw $this->explainFailedDelivery($employeeUuid);
        }

        return new PinDeliveryRecord(
            employeeUuid: $employeeUuid,
            deliveredAt: $deliveredAt,
            deliveredByUserUuid: $deliveredByUserUuid,
        );
    }

    public function statusFor(string $employeeUuid): ?PinStatus
    {
        $employee = Employee::query()->where('uuid', $employeeUuid)->first();

        return $employee instanceof Employee ? $this->statusOf($employee) : null;
    }

    public function statusesFor(array $employeeUuids): array
    {
        if ($employeeUuids === []) {
            return [];
        }

        $statuses = [];

        /** @var Employee $employee */
        foreach (Employee::query()->whereIn('uuid', $employeeUuids)->get() as $employee) {
            $statuses[$employee->uuid] = $this->statusOf($employee);
        }

        return $statuses;
    }

    private function statusOf(Employee $employee): PinStatus
    {
        if ($employee->pin_delivered_at !== null) {
            return PinStatus::Delivered;
        }

        return $employee->pin_issued_at === null ? PinStatus::Pending : PinStatus::Issued;
    }

    private function explainFailedDelivery(string $employeeUuid): PinNotIssued|PinAlreadyDelivered
    {
        $delivered = Employee::query()
            ->where('uuid', $employeeUuid)
            ->whereNotNull('pin_delivered_at')
            ->exists();

        return $delivered
            ? PinAlreadyDelivered::forEmployee($employeeUuid)
            : PinNotIssued::forEmployee($employeeUuid);
    }

    /**
     * Clave interna de la cuenta de gestion que entrego, a partir de su UUID
     * publico.
     *
     * Quien llega aqui esta autenticado, asi que no encontrarlo no es un caso de
     * negocio: es una incoherencia entre la sesion y la tabla, y falla en alto.
     */
    private function internalIdOf(string $userUuid): int
    {
        $id = DB::table('users')->where('uuid', $userUuid)->value('id');

        if (! is_int($id) && ! is_numeric($id)) {
            throw new RuntimeException('La cuenta de gestion '.$userUuid.' no existe en la instalacion.');
        }

        return (int) $id;
    }
}
