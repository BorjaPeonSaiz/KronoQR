<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Adapter;

use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Agrupa en una ventana las divulgaciones repetidas del **mismo actor sobre el
 * mismo conjunto de datos**, para los conjuntos que se **sondean** (RS-05,
 * RL-15, ADR-010, ADR-037).
 *
 * ## Que problema resuelve
 *
 * La regla general de este producto es que cada divulgacion deja su asiento:
 * quien lista la plantilla o se descarga el padron lo hace una vez, y hay que
 * poder decir despues que se llevo. La presencia en vivo rompe esa premisa: el
 * panel la pide **cada 15 segundos** cuando el WebSocket no llega (RNF-D-03,
 * ADR-011), y con tres puestos abiertos eso son mas de veinte mil lecturas
 * identicas al dia.
 *
 * Dos consecuencias, y la segunda es la grave:
 *
 *   1. `audit_log` se retiene cuatro años y se enseña en una inspeccion. Veinte
 *      mil filas diarias que dicen «RRHH miro quien estaba dentro» no responden
 *      mejor a RL-15 que una fila por ventana: la ahogan, y con ella el resto
 *      del trail.
 *   2. Cada escritura toma el `pg_advisory_xact_lock` **global** de ADR-010, el
 *      mismo por el que pasa cada fichaje. Una funcionalidad accesoria (ADR-023)
 *      metiendo escrituras serializadas en el camino critico del cambio de turno
 *      es lo que las reglas duras 15 y 19 prohiben.
 *
 * **El mecanismo es el que nombra ADR-037** al hablar de la contencion del
 * candado: *«la palanca es la frecuencia —agrupar lecturas identicas del mismo
 * actor en una ventana— y cabe entera detras del puerto»*. Aquella decision lo
 * escribio para este puerto exactamente; hasta ahora no habia hecho falta porque
 * ningun conjunto se sondeaba.
 *
 * ## Solo los conjuntos declarados
 *
 * `config('compliance.disclosure_grouping.datasets')`, hoy `live_presence` y
 * nada mas. Es deliberado que la agrupacion **no** se aplique a todo: el
 * directorio de plantilla, el padron del quiosco y la exportacion legal siguen
 * dejando un asiento por lectura, porque ahi cada lectura es un acto distinto de
 * una persona y no el latido de una pantalla abierta. Ampliar la lista es una
 * decision sobre el valor probatorio del trail, y por eso vive en configuracion
 * a la vista y no en un `if` dentro de un caso de uso.
 *
 * ## El hecho no desaparece: se agrupa
 *
 * **La primera divulgacion de cada ventana se escribe siempre.** Lo que se
 * agrupa es la repeticion, no el hecho, igual que en
 * {@see GroupedAuthorizationJournal}. Las repeticiones se cuentan y el numero
 * viaja en el `repeated_since_last_entry` del **siguiente** asiento del mismo
 * par, de modo que quien lea el trail ve «esta cuenta tuvo la presencia delante,
 * y entre este apunte y el anterior la refresco 58 veces mas».
 *
 * **`record_count` es el de la primera lectura de la ventana**, no una suma. Y
 * tiene que serlo: sumar las filas de sesenta sondeos daria «se llevo 6.000
 * registros» cuando lo que hubo fue la misma lista sesenta veces, que exagera
 * una brecha tanto como quedarse corto la minimiza.
 *
 * **Consecuencia asumida:** si el panel se cierra a mitad de ventana, la cola de
 * ese contador no llega a ningun asiento. Es aceptable por lo mismo que alli: lo
 * que RL-15 necesita saber es **que** esa cuenta tuvo el dato delante, y eso
 * consta desde el primer apunte.
 *
 * ## Por que aqui y no en el caso de uso
 *
 * Porque esto es infraestructura: usa la cache y el actor de la peticion en
 * curso. El decorado sigue siendo {@see AuditedPersonalDataAccessLog}, que es
 * quien tiene la cadena de hash, y ninguno de los llamantes del puerto
 * —`Kiosk`, `Workforce`, `Reporting`, `Compliance`— se entera.
 */
final readonly class GroupedPersonalDataAccessLog implements PersonalDataAccessLog
{
    private const string SEAT_PREFIX = 'audit:disclosure-seat:';

    private const string COUNT_PREFIX = 'audit:disclosure-count:';

    /**
     * @param  list<string>  $groupedDatasets  Los conjuntos que se sondean. El resto pasa intacto.
     */
    public function __construct(
        private PersonalDataAccessLog $disclosures,
        private CurrentAuditContext $context,
        private Cache $cache,
        private array $groupedDatasets,
        private int $windowSeconds,
    ) {}

    public function recordDisclosure(string $dataset, int $recordCount, array $context = []): void
    {
        if ($this->windowSeconds < 1 || ! \in_array($dataset, $this->groupedDatasets, true)) {
            $this->disclosures->recordDisclosure($dataset, $recordCount, $context);

            return;
        }

        $key = $this->keyFor($dataset);

        // El contador vive mas que la ventana a proposito: tiene que sobrevivir
        // al hueco entre que caduca el asiento y llega la lectura que abre la
        // ventana siguiente.
        $this->cache->add(self::COUNT_PREFIX.$key, 0, $this->windowSeconds * 4);

        $pending = $this->cache->increment(self::COUNT_PREFIX.$key);
        $pending = \is_int($pending) ? $pending : 1;

        // `add()` es la operacion atomica que reparte el asiento: solo una de N
        // peticiones simultaneas se lo lleva. Un `has()` seguido de un `put()`
        // dejaria pasar a todas las que entraran a la vez, que es justo el caso
        // que hay que acotar cuando tres paneles sondean al mismo ritmo.
        if (! $this->cache->add(self::SEAT_PREFIX.$key, true, $this->windowSeconds)) {
            return;
        }

        $this->cache->forget(self::COUNT_PREFIX.$key);

        $this->disclosures->recordDisclosure($dataset, $recordCount, [
            ...$context,
            // Cuantas lecturas representa este asiento, esta incluida. `1` es el
            // caso normal de quien abre la pantalla y la cierra.
            'repeated_since_last_entry' => $pending,
        ]);
    }

    /**
     * Clave estable del par actor + conjunto.
     *
     * El actor sale del contexto de la peticion —quien accede no puede declarar
     * quien es— y se usa su forma canonica, que es tipo mas identificador y
     * **nunca** un nombre (regla dura 21). Va por `sha1` porque la clave viaja a
     * Redis en produccion: no hace falta que un identificador de cuenta figure en
     * el espacio de claves de la cache, y el hash no tiene que resistir un ataque
     * —solo evitar colisiones entre dos pares—.
     */
    private function keyFor(string $dataset): string
    {
        return sha1($this->context->actor()->canonical().'|'.$dataset);
    }
}
