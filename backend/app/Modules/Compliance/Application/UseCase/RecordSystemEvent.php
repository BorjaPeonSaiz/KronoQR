<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Command\RecordSystemEventCommand;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Domain\ValueObject\SystemEventPayload;

/**
 * Deja asiento de que la instalacion se ha actualizado o de que se ha restaurado
 * una copia previa (**RF-PD-10**, RL-04, RS-07, regla dura 6, tarea 5.7).
 *
 * ## Que agujero cierra
 *
 * `update.sh` verifica la cadena de auditoria antes de tocar nada y despues de
 * migrar, y si algo falla restaura la copia previa. Hasta ahora ninguna de las
 * tres cosas dejaba rastro **dentro** del registro. El caso grave es la vuelta
 * atras: la cadena que queda es la de la copia, es integra, y
 * `compliance:verify-audit-chain` sale en verde. El intervalo descartado —que
 * puede contener fichajes reales de un turno de noche— no deja ningun hueco
 * visible, porque los huecos se ven en la cadena y esa cadena no tiene ninguno.
 *
 * Un registro horario que perdio horas sin decirlo no cumple RL-04.
 *
 * ## La decision: se escribe SOBRE la cadena restaurada
 *
 * El asiento de la vuelta atras se escribe **despues** de restaurar, no antes.
 * Es lo unico que lo hace util: entra como eslabon siguiente al ultimo que
 * sobrevivio, asi que su `prev_hash` es la huella del ultimo hecho de la copia y
 * el asiento significa, literalmente, «lo que hay antes de mi es la copia de las
 * HH:MM». Escribirlo antes de restaurar habria sido escribirlo en la cadena que
 * se tira: el propio asiento que explica la perdida se habria perdido con ella.
 *
 * ## Por que un caso de uso y no un listener
 *
 * Los otros veinte asientos del catalogo nacen de un evento de dominio que un
 * listener de `Compliance/Infrastructure` recoge. Este no puede: **quien lo
 * provoca no es codigo del producto**, es un script de shell que se ejecuta
 * mientras la aplicacion esta en mantenimiento y, en la vuelta atras, sobre una
 * base de datos que acaba de ser sustituida. No hay evento que publicar porque
 * no hay proceso vivo que lo publique. La via es la otra que concede el §1.6: un
 * caso de uso publico con interfaz explicita, invocado desde un comando de
 * consola.
 *
 * **El actor es siempre `system`** y no se puede elegir: no hay sesion de nadie
 * detras, y un asiento firmado por una persona diria que esa persona decidio
 * descartar un intervalo del registro.
 *
 * **El sujeto es la instalacion** —`installation`, sin identificador—, porque no
 * recae sobre ninguna fila: recae sobre el producto entero. No hay `subject_id`
 * que poner y ADR-040 garantiza que solo hay una instalacion por despliegue.
 *
 * **No abre transaccion**, igual que {@see RecordAuditEntry}: es un unico
 * asiento y el adaptador ya toma el candado de la cadena (ADR-010).
 */
final readonly class RecordSystemEvent
{
    /**
     * El sujeto de los dos asientos: la instalacion entera, sin identificador.
     */
    private const string SUBJECT = 'installation';

    public function __construct(private RecordAuditEntry $audit) {}

    public function handle(RecordSystemEventCommand $command): AuditEntry
    {
        $event = SystemEventPayload::for($command->action, $command->data);

        return $this->audit->handle(new RecordAuditEntryCommand(
            actor: AuditActor::system(),
            action: $event->action,
            subject: AuditSubject::of(self::SUBJECT),
            payload: $event->payload,
        ));
    }
}
