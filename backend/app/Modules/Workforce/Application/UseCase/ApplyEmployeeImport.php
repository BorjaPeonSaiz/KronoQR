<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Command\PinProvisioning;
use App\Modules\Workforce\Application\Command\RegisterEmployeeCommand;
use App\Modules\Workforce\Application\Command\UpdateEmployeeCommand;
use App\Modules\Workforce\Application\Port\EmployeeImportDirectory;
use App\Modules\Workforce\Application\Port\ParentRowLocks;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeesImported;
use App\Modules\Workforce\Domain\ValueObject\ImportColumnMap;
use App\Modules\Workforce\Domain\ValueObject\ImportedEmployee;
use App\Modules\Workforce\Domain\ValueObject\ImportOutcome;
use App\Modules\Workforce\Domain\ValueObject\ImportReport;
use App\Modules\Workforce\Domain\ValueObject\ImportRow;

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
 * propio. Un alta por importacion tiene que publicar `EmployeeHired` —con su
 * asiento `employee.hired`— y generar su codigo opaco reintentando contra el
 * `UNIQUE`, exactamente igual que un alta desde el panel. Un camino paralelo
 * seria un alta de segunda categoria, y las personas que entraran por el
 * tendrian medio ciclo de vida.
 *
 * Se declaran distintas dos cosas, cada una por su nombre:
 *
 * - el **origen** (`viaImport`), para que el uso del plan se cuente **una vez
 *   por importacion** y no una vez por fila (ADR-028, H-04 de la revision de la
 *   3.8);
 * - el **PIN, pendiente** ({@see PinProvisioning::DeferredToCardHandover}).
 *   Mas abajo, el porque.
 *
 * ## Una baja entre la comprobacion y la aplicacion tumba el lote (ADR-046 §5)
 *
 * La comprobacion ya rechaza las lineas de personas de baja
 * (`employee_terminated`). Si la baja llega despues, la linea entra aqui como
 * `update`, la modificacion lee la ficha con candado, la ve de baja y lanza
 * `EmployeeAlreadyTerminated`: la transaccion entera revierte y la respuesta es
 * `409`, sin ninguna fila escrita. Se aplica exactamente lo que se reviso.
 *
 * ## Lo que esta importacion NO hace
 *
 * **No manda nada por correo** (regla dura 11, ADR-014). La credencial es una
 * tarjeta fisica: importar cuarenta personas deja cuarenta tarjetas pendientes
 * de emitir, imprimir y entregar, y quien lo dice es el panel de estado de
 * credenciales (RF-QR-08). **No cambia `hired_at`** de quien ya existe (regla
 * dura 5): el informe ya lo avisa y aqui simplemente no viaja, porque
 * {@see UpdateEmployeeCommand} no tiene ese campo.
 *
 * **No emite PIN** (RF-ID-09, RF-GP-05). Cada alta nace con el PIN pendiente:
 * deja `employee.hired` con `via_import` y ningun `pin.issued`. Un PIN se
 * muestra una sola vez y se entrega en mano junto a la tarjeta; en un informe de
 * quinientas filas no lo veria nadie, y emitirlo para no ensenarlo dejaria
 * secretos que habria que volver a emitir de todos modos. RRHH lo emite desde la
 * ficha al entregar la tarjeta, y mientras tanto la persona sale en el listado
 * con `pin_status=pending`: el pendiente esta a la vista, no escondido.
 *
 * De paso, la importacion **no calcula ningun bcrypt** (antes, unos 160 ms por
 * alta con el coste 12 de produccion, 80 s para 500): ningun hash puede correr
 * con la cadena de `audit_log` tomada ni acercar la peticion al
 * `max_execution_time` (ADR-046 §1.1 punto 5).
 */
final readonly class ApplyEmployeeImport
{
    public function __construct(
        private RegisterEmployeeHandler $register,
        private UpdateEmployeeHandler $update,
        private EmployeeImportDirectory $directory,
        private ParentRowLocks $parentRows,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private EmployeeWriteRetry $retry,
    ) {}

    public function handle(ImportReport $report): ImportReport
    {
        $applied = $this->retry->run('employee.import', function () use ($report): array {
            // FILAS PADRE ANTES DEL PRIMER ASIENTO (ADR-046 §1.1 punto 2, §5).
            // El primer alta o modificacion toma la cadena de `audit_log` y no la
            // suelta hasta el commit; a partir de ahi, pedir una fila padre seria
            // pedirla con la cadena en la mano y cerrar un ciclo con el renombrado
            // de un departamento o del centro. Se toman aqui, de una vez, el centro
            // y TODOS los departamentos del mapa con el que se resuelve cada
            // linea, ordenados por `id`. Las fichas no se bloquean de antemano: la
            // cadena ya serializa a todos sus escritores.
            //
            // El mapa se lee AQUI DENTRO y no antes de abrir la transaccion:
            // leido fuera, un departamento renombrado entre la lectura y la
            // escritura se resolvia con su nombre viejo (cuando la importacion
            // calculaba el bcrypt de las altas, esa ventana eran hasta ~80 s
            // con 500 filas). Dentro, entre la
            // lectura y el candado caben milisegundos, y un renombrado en ese
            // hueco resuelve el mismo `id` que si hubiera llegado justo despues
            // de esta importacion; a partir del `FOR KEY SHARE` ya no puede
            // renombrarse hasta el commit (ADR-046 §5).
            $this->parentRows->shareInstallationSite();
            $departments = $this->directory->departmentsByNormalisedName();
            $this->parentRows->shareDepartments(array_values($departments));

            return $this->applyRows($report, $departments);
        });

        // El asiento del LOTE se publica DESPUES de confirmar, igual que el resto
        // de eventos de este modulo: un asiento de una carga que luego revierte
        // dejaria en el trail una plantilla que no existe. Los asientos de cada
        // alta si van dentro, porque los publica `RegisterEmployeeHandler` en su
        // propia transaccion anidada (ADR-027).
        //
        // De este evento cuelga ademas el **unico** conteo de uso del plan de la
        // importacion (ADR-028): `created` es lo que de verdad entro, ya
        // confirmado, y por eso es la cifra correcta contra la que comparar. Este
        // modulo sigue sin saber nada de licencias — publica el hecho y quien
        // cuenta es `Product` (doc 02 §1.6).
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
     * @param  array<string, int>  $departments
     * @return list<ImportRow>
     */
    private function applyRows(ImportReport $report, array $departments): array
    {
        $rows = [];

        foreach ($report->rows as $row) {
            $rows[] = match ($row->outcome) {
                ImportOutcome::CREATE => $this->create($row, $departments),
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
    private function create(ImportRow $row, array $departments): ImportRow
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
            // PIN pendiente, pedido por su nombre (RF-ID-09, RF-GP-05): se emite
            // desde la ficha al entregar la tarjeta, que es cuando hay alguien
            // delante para recibirlo. Sin hash que calcular, ninguna fila hace
            // bcrypt.
            pin: PinProvisioning::DeferredToCardHandover,
            // Marca de origen, y no un alta distinta: con ella el uso del plan
            // se cuenta UNA VEZ por importacion —desde
            // `EmployeesImported`, mas abajo— en lugar de una vez por fila. Con
            // la cuenta por fila, un hotel con plan de 80 que importara 300
            // personas escribia trescientos asientos `license.plan_exceeded`
            // casi identicos, todos bajo el `pg_advisory_xact_lock` global de
            // `audit_log` (ADR-010), que es el mismo por el que pasa cada fichaje
            // del hotel (H-04 de la revision de la 3.8).
            viaImport: true,
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
