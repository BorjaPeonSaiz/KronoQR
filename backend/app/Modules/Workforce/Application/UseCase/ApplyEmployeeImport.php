<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Command\RegisterEmployeeCommand;
use App\Modules\Workforce\Application\Command\UpdateEmployeeCommand;
use App\Modules\Workforce\Application\Pin\PinGenerator;
use App\Modules\Workforce\Application\Port\EmployeeImportDirectory;
use App\Modules\Workforce\Application\Port\PinHasher;
use App\Modules\Workforce\Application\Port\PinMaterial;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeesImported;
use App\Modules\Workforce\Domain\ValueObject\ImportColumnMap;
use App\Modules\Workforce\Domain\ValueObject\ImportedEmployee;
use App\Modules\Workforce\Domain\ValueObject\ImportOutcome;
use App\Modules\Workforce\Domain\ValueObject\ImportReport;
use App\Modules\Workforce\Domain\ValueObject\ImportRow;
use Illuminate\Database\ConnectionInterface;

/**
 * Escribe lo que {@see PlanEmployeeImport} decidio (**RF-GP-05**, fase de
 * aplicacion).
 *
 * ## Consume el informe, no vuelve a decidir
 *
 * Recibe el `ImportReport` ya calculado y se limita a ejecutarlo. Es lo que
 * garantiza que **lo que se escribe es exactamente lo que se simulo**: con la
 * decision repetida aqui, los dos caminos podrian divergir con el tiempo y nadie
 * lo notaria hasta que el informe mintiera.
 *
 * ## Todo o nada, en una sola transaccion
 *
 * Una importacion a medias es peor que una que no arranca: deja al cliente sin
 * saber quien esta dado de alta y quien no, y la segunda pasada crearia
 * duplicados de lo que si entro. Con ≤500 lineas —el tope de
 * `config/workforce.php`— la transaccion es corta.
 *
 * **Las lineas rechazadas no revierten nada**: se saltan. Tumbar el lote entero
 * por una celda con una fecha mal escrita obligaria a repetir la revision de las
 * otras treinta y nueve, y en la practica lleva a que alguien borre la linea
 * problematica en vez de corregirla.
 *
 * ## Reutiliza el alta y la modificacion de siempre
 *
 * {@see RegisterEmployeeHandler} y {@see UpdateEmployeeHandler}, no un camino
 * propio. Un alta por importacion tiene que emitir su PIN —con su asiento
 * `pin.issued`—, publicar `EmployeeHired` —del que cuelga el conteo de uso del
 * plan— y generar su codigo opaco reintentando contra el `UNIQUE`, exactamente
 * igual que un alta desde el panel. Un camino paralelo seria un alta de segunda
 * categoria, y las personas que entraran por el tendrian medio ciclo de vida.
 *
 * ## Lo que esta importacion NO hace
 *
 * **No manda nada por correo** (regla dura 11, ADR-014). La credencial es una
 * tarjeta fisica: importar cuarenta personas deja cuarenta tarjetas pendientes
 * de emitir, imprimir y entregar, y quien lo dice es el panel de estado de
 * credenciales (RF-QR-08). **No cambia `hired_at`** de quien ya existe (regla
 * dura 5): el informe ya lo avisa y aqui simplemente no viaja, porque
 * {@see UpdateEmployeeCommand} no tiene ese campo.
 */
final readonly class ApplyEmployeeImport
{
    public function __construct(
        private RegisterEmployeeHandler $register,
        private UpdateEmployeeHandler $update,
        private EmployeeImportDirectory $directory,
        private PinGenerator $pinGenerator,
        private PinHasher $hasher,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    public function handle(ImportReport $report): ImportReport
    {
        $departments = $this->directory->departmentsByNormalisedName();

        // TODO EL bcrypt, ANTES DE ABRIR LA TRANSACCION. Es la correccion de la
        // revision de la 5.5 y no es una optimizacion: es lo que hace que este
        // endpoint pueda existir al tamaño que documenta.
        $material = $this->pinMaterialFor($report);

        $applied = $this->connection->transaction(
            fn (): array => $this->applyRows($report, $departments, $material),
        );

        // El asiento del LOTE se publica DESPUES de confirmar, igual que el resto
        // de eventos de este modulo: un asiento de una carga que luego revierte
        // dejaria en el trail una plantilla que no existe. Los asientos de cada
        // alta si van dentro, porque los publica `RegisterEmployeeHandler` en su
        // propia transaccion anidada (ADR-027).
        $this->events->publish(new EmployeesImported(
            fileSha256: $report->sha256,
            created: $report->countOf(ImportOutcome::CREATE),
            updated: $report->countOf(ImportOutcome::UPDATE),
            unchanged: $report->countOf(ImportOutcome::UNCHANGED),
            rejected: $report->countOf(ImportOutcome::REJECT),
            occurredAt: $this->clock->now(),
        ));

        return $report->withRows($applied);
    }

    /**
     * El PIN y su hash de cada alta, **calculados fuera de la transaccion**.
     *
     * ## Por que esto no es una optimizacion
     *
     * bcrypt con el coste 12 de produccion cuesta unos **160 ms por PIN**
     * (medido en el contenedor). Con el calculo dentro de la transaccion, 500
     * altas eran **80 segundos** con dos consecuencias que nadie habria
     * relacionado con una importacion:
     *
     * 1. **El hotel deja de fichar.** El primer asiento del lote toma el
     *    `pg_advisory_xact_lock` global de `audit_log` y no lo suelta hasta el
     *    commit (ADR-010). Cada escaneo del quiosco se serializa detras: una
     *    importacion a media mañana dejaba la tablet de la entrada esperando
     *    minuto y medio.
     * 2. **La peticion moria.** `max_execution_time` son 60 s, y el corte
     *    llegaba **despues** de que quien importa hubiera confirmado — sin saber
     *    si habia entrado alguien.
     *
     * Ninguna de las dos la veia la suite, y no puede verlas: `phpunit.xml` fija
     * `BCRYPT_ROUNDS=4` (0,7 ms) para que las pruebas no tarden horas, asi que el
     * sintoma —el tiempo— no se reproduce. `EmployeeImportPerformanceTest`
     * afirma la propiedad **estructural** de la que dependen las dos: que cuando
     * se calcula un hash, la transaccion del lote **todavia no esta abierta**.
     * Eso si se rompe el dia que alguien mueva el calculo de sitio.
     *
     * ## Lo que NO cambia
     *
     * El todo-o-nada. Los hashes se calculan antes, pero se **escriben** dentro
     * de la misma transaccion que el alta: un empleado sin PIN sigue sin poder
     * existir, y una fila que falle sigue revirtiendo el lote entero.
     *
     * Solo se calcula para las filas que crean: `update` y `unchanged` no emiten
     * PIN, y `reject` no escribe nada.
     *
     * @return array<int, PinMaterial> Indexado por el numero de linea del fichero.
     */
    private function pinMaterialFor(ImportReport $report): array
    {
        $material = [];

        foreach ($report->rows as $row) {
            if ($row->outcome === ImportOutcome::CREATE) {
                $material[$row->line] = $this->hasher->hash($this->pinGenerator->generate());
            }
        }

        return $material;
    }

    /**
     * @param  array<string, int>  $departments
     * @param  array<int, PinMaterial>  $material
     * @return list<ImportRow>
     */
    private function applyRows(ImportReport $report, array $departments, array $material): array
    {
        $rows = [];

        foreach ($report->rows as $row) {
            $rows[] = match ($row->outcome) {
                ImportOutcome::CREATE => $this->create($row, $departments, $material[$row->line] ?? null),
                ImportOutcome::UPDATE => $this->modify($row, $departments),
                // `unchanged` y `reject` no escriben: la primera porque no hay
                // nada que cambiar y la segunda porque no se pudo interpretar.
                default => $row,
            };
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $departments
     */
    private function create(ImportRow $row, array $departments, ?PinMaterial $material): ImportRow
    {
        $employee = $row->employee;

        if (! $employee instanceof ImportedEmployee) {
            return $row;
        }

        $registered = $this->register->handle(new RegisterEmployeeCommand(
            departmentId: $this->departmentIdOf($employee, $departments),
            firstName: $employee->firstName,
            lastName: $employee->lastName,
            email: $employee->email,
            // Ultimo punto en el que el documento existe en claro: el
            // repositorio lo convierte en `digest(?, 'sha256')` dentro de la
            // propia sentencia (RL-08).
            nationalId: $employee->nationalId,
            hiredAt: self::hiredAtOf($employee),
            locale: $employee->locale ?? 'es',
            // Ya calculado FUERA de la transaccion. Nunca es nulo para una fila
            // `create`, y si lo fuera el alta lo generaria dentro: preferible una
            // importacion lenta a una fila sin PIN, que es una persona que no
            // puede fichar por respaldo (RF-AT-11) ni entrar al portal (RL-05).
            pinMaterial: $material,
        ));

        return $row->appliedAs($registered->employee->uuid);
    }

    /**
     * @param  array<string, int>  $departments
     */
    private function modify(ImportRow $row, array $departments): ImportRow
    {
        $employee = $row->employee;
        $uuid = $row->employeeUuid;

        if (! $employee instanceof ImportedEmployee || $uuid === null) {
            return $row;
        }

        $departmentId = $this->departmentIdOf($employee, $departments);

        $this->update->handle(new UpdateEmployeeCommand(
            uuid: $uuid,
            firstName: $employee->firstName,
            lastName: $employee->lastName,
            email: $employee->email,
            // `emailGiven` solo cuando el fichero TRAE correo: una columna
            // ausente o una celda vacia no son una instruccion de borrado
            // (regla dura 12).
            emailGiven: $employee->email !== null,
            departmentId: $departmentId,
            departmentGiven: $departmentId !== null,
            // El estado no se toca: una importacion no reincorpora a nadie ni da
            // de baja a nadie. La baja es `POST /employees/{uuid}/offboard`, que
            // lleva fecha de cese y revoca la credencial (RN-14).
            status: null,
            locale: $employee->locale,
        ));

        return $row;
    }

    /**
     * @param  array<string, int>  $departments
     */
    private function departmentIdOf(ImportedEmployee $employee, array $departments): ?int
    {
        return $employee->department === null
            ? null
            : ($departments[ImportColumnMap::normalise($employee->department)] ?? null);
    }

    /**
     * La fecha ya validada, en el formato que espera el alta.
     *
     * La validacion ocurrio en {@see PlanEmployeeImport}: una linea con fecha
     * ilegible es `reject` y no llega hasta aqui. El respaldo existe porque el
     * tipo lo permite, no porque el caso sea alcanzable.
     */
    private static function hiredAtOf(ImportedEmployee $employee): string
    {
        $date = $employee->hiredAt === null ? null : PlanEmployeeImport::parseDate($employee->hiredAt);

        return $date?->format('Y-m-d') ?? '';
    }
}
