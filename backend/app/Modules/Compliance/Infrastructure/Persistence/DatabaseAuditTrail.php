<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\AuditTrail;
use App\Modules\Compliance\Domain\AuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use Illuminate\Database\ConnectionInterface;

/**
 * El escritor de `audit_log` (regla dura 6, ADR-010, doc 02 §7.4).
 *
 * **Tres decisiones que no son de estilo.**
 *
 * 1. **Candado consultivo de transaccion.** Leer el ultimo `hash` y escribir el
 *    siguiente tiene que ser atomico *entre si*. Sin el candado, dos apuntes
 *    simultaneos leerian el mismo `prev_hash`, la cadena naceria bifurcada y
 *    `compliance:verify-audit-chain` denunciaria una rotura que nadie ha
 *    causado — un falso positivo permanente sobre la alerta critica de RS-07.
 *    Es `pg_advisory_xact_lock` y no `SELECT … FOR UPDATE` porque **no hay fila
 *    que bloquear** cuando la tabla esta vacia, que es justo el caso de la
 *    primera entrada de la instalacion.
 * 2. **Se une a la transaccion de quien llama.** Si el caso de uso que audita ya
 *    abrio una, esta escritura entra en ella: fallar aqui deshace la accion
 *    auditada, que es lo que hace que «toda accion con relevancia legal escribe
 *    en `audit_log`» sea una garantia. Si no hay ninguna abierta, se abre una
 *    propia: el candado consultivo *de transaccion* necesita una para
 *    sostenerse.
 * 3. **Se persiste el mismo JSON que se encadena.** `AuditPayload::encode()`
 *    produce la forma canonica y es esa la que va a la columna. Asi, mirar la
 *    fila a mano y recalcular su hash dan lo mismo. PostgreSQL reordenara las
 *    claves al guardarlas como `jsonb` —lo hace por longitud y despues por
 *    bytes—, y da igual: la canonicalizacion ordena al leer.
 */
final readonly class DatabaseAuditTrail implements AuditTrail
{
    /**
     * Identificador del candado. Dos enteros de 32 bits fijos, elegidos a mano
     * y documentados aqui para que nadie reutilice el par por accidente: si otro
     * proceso tomara este mismo candado para otra cosa, serializaria contra los
     * fichajes sin que se viera la relacion.
     */
    private const int LOCK_NAMESPACE = 0x4B52; // 'KR'

    private const int LOCK_RESOURCE = 0x4155;  // 'AU'

    public function __construct(private ConnectionInterface $connection) {}

    public function append(AuditEntryDraft $draft): AuditEntry
    {
        // `transaction()` sobre una transaccion ya abierta crea un SAVEPOINT y
        // no una segunda transaccion: el candado de transaccion sigue siendo el
        // de la exterior y se suelta con ella.
        return $this->connection->transaction(function () use ($draft): AuditEntry {
            $this->connection->statement(
                'SELECT pg_advisory_xact_lock(?, ?)',
                [self::LOCK_NAMESPACE, self::LOCK_RESOURCE],
            );

            $entry = AuditChain::link($draft, $this->previousHash());

            $id = $this->connection->table(AuditLogSchema::TABLE)->insertGetId([
                'occurred_at' => $draft->occurredAt->format('Y-m-d H:i:s.uP'),
                'actor_type' => $draft->actor->type->value,
                'actor_id' => $draft->actor->id,
                'action' => $draft->action->value,
                'subject_type' => $draft->subject->type,
                'subject_id' => $draft->subject->id,
                'payload' => $draft->payload->encode(),
                'prev_hash' => $entry->previousHash,
                'hash' => $entry->hash,
                'ip' => $draft->ip,
                'user_agent' => $draft->userAgent,
            ]);

            return $entry->withId($id);
        });
    }

    /**
     * El `hash` del ultimo eslabon, o la genesis si no hay ninguno.
     *
     * **El caso raro que no lo es tanto:** la tabla puede estar vacia sin que
     * la instalacion sea nueva, si la retencion solto la ultima particion viva.
     * Entonces la cadena no vuelve a empezar por la genesis: continua desde el
     * `last_hash` del ancla mas reciente (ADR-027). Sin esto, el dia siguiente a
     * esa purga la cadena tendria dos origenes y el verificador lo diria.
     */
    private function previousHash(): string
    {
        /** @var object{hash: string}|null $last */
        $last = $this->connection->table(AuditLogSchema::TABLE)
            ->select('hash')
            ->orderByDesc('id')
            ->limit(1)
            ->first();

        if ($last !== null) {
            return $last->hash;
        }

        /** @var object{last_hash: string}|null $anchor */
        $anchor = $this->connection->table(AuditLogSchema::ANCHORS_TABLE)
            ->select('last_hash')
            ->orderByDesc('partition_year')
            ->limit(1)
            ->first();

        if ($anchor === null) {
            return AuditChain::genesisHash();
        }

        return $anchor->last_hash;
    }
}
