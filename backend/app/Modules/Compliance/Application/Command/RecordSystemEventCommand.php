<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Command;

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\SystemEventPayload;

/**
 * La orden de dejar asiento de un hecho del ciclo de vida de la instalacion
 * (RF-PD-10, tarea 5.7).
 *
 * **No lleva actor.** No es un olvido: estas acciones solo las puede firmar el
 * sistema, asi que ofrecer el campo seria ofrecer la posibilidad de equivocarse
 * —o de mentir— sobre quien restauro una copia. Lo pone el caso de uso y lo
 * vuelve a comprobar el dominio en {@see AuditEntryDraft}.
 *
 * **Tampoco lleva `occurred_at`.** El hecho ocurre cuando se escribe: el
 * instalador invoca el comando en el mismo minuto en el que termino de migrar o
 * de restaurar. Un momento propio, aqui, solo serviria para que un reloj mal
 * puesto colocara el asiento antes de las filas que explica.
 *
 * `data` viaja como array sin validar a proposito: quien lo valida es
 * {@see SystemEventPayload}, en el
 * dominio, y no la consola. Una validacion en la consola seria una segunda copia
 * de la lista cerrada, y dos listas cerradas divergen.
 */
final readonly class RecordSystemEventCommand
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(
        public AuditAction $action,
        public array $data,
    ) {}
}
