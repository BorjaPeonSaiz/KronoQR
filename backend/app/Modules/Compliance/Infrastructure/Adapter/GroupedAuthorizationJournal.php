<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Adapter;

use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Shared\Application\Port\AuthorizationJournal;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Agrupa las denegaciones repetidas del **mismo actor sobre el mismo conjunto de
 * datos** en una ventana, y deja pasar una sola al `audit_log` (RF-ID-03, RS-05,
 * ADR-010, ADR-037).
 *
 * ## Que problema resuelve
 *
 * `access.denied` es la unica escritura de `audit_log` que **provoca quien no esta
 * autorizado**. Todas las demas son actos de gestion; esta la desencadena una
 * peticion denegada, y por tanto un bucle de peticiones denegadas es un bucle de
 * escrituras. Cada una toma el `pg_advisory_xact_lock` **global** de ADR-010 —el
 * mismo por el que pasa cada fichaje—, asi que un responsable recorriendo UUID
 * ajenos mete escrituras serializadas en el camino critico del cambio de turno, y
 * llena de ruido cuatro años de una tabla que se enseña en una inspeccion.
 *
 * **El mecanismo es el que nombra ADR-037** cuando habla de la contencion del
 * candado: *«la palanca es la frecuencia —agrupar lecturas identicas del mismo
 * actor en una ventana— y cabe entera detras del puerto»*. Aquella decision lo
 * escribio para `PersonalDataAccessLog`; aqui aplica igual y por el mismo motivo,
 * con la diferencia de que el volumen no lo controla una persona autorizada sino
 * quien esta siendo rechazado.
 *
 * ## El asiento no desaparece: se agrupa
 *
 * **La primera denegacion de cada ventana se escribe siempre**, y eso es lo que el
 * escenario «Aislamiento por departamento» del doc 01 §11 exige por escrito —*«el
 * intento queda registrado en el trail de auditoria»*—. Lo que se agrupa es la
 * repeticion, no el hecho.
 *
 * Las repeticiones **no se pierden**: se cuentan, y el numero viaja en el
 * `repeated_since_last_entry` del **siguiente** asiento del mismo par. Asi, quien
 * lea el trail de un incidente ve «esta cuenta fue a por fichas ajenas, y entre
 * este apunte y el anterior lo intento 340 veces mas», que es mas informacion de
 * la que dan 341 filas identicas.
 *
 * **Consecuencia asumida:** si los intentos cesan a mitad de ventana, la cola de
 * ese contador no llega a ningun asiento. Es aceptable porque lo que la norma y el
 * escenario piden es que conste **que** lo intento, y eso ya consta desde el primer
 * apunte; el contador es senal de deteccion, no el registro legal.
 *
 * ## La granularidad es actor + dataset, no actor + persona
 *
 * A proposito. Enumerar UUID ajenos es justo el ataque, asi que agrupar por
 * `employee_uuid` no agruparia nada: cada intento traeria una clave distinta y el
 * techo no existiria. El `employee_uuid` de la **primera** denegacion de cada
 * ventana si queda en su asiento, que es el que responde «¿a por quien fue?».
 *
 * ## Por que aqui y no en `ScopeGuard`
 *
 * Porque esto es infraestructura: usa la cache y el actor de la peticion. El
 * decorado sigue siendo {@see AuditedAuthorizationJournal}, que es quien tiene la
 * cadena de hash, y ninguno de los tres llamantes del puerto —`Workforce`,
 * `Reporting` y `Attendance`— se entera.
 *
 * ## Y no sustituye al limite de peticiones
 *
 * La zona `throttle:management` pone techo a **cuantas peticiones** acepta el
 * proceso; esto pone techo a **cuantas filas** escribe. Sin la primera, el
 * servidor sigue haciendo el trabajo de resolver la ficha y denegarla; sin la
 * segunda, 120 denegaciones por minuto y por cuenta siguen siendo 120 asientos.
 */
final readonly class GroupedAuthorizationJournal implements AuthorizationJournal
{
    private const string SEAT_PREFIX = 'audit:denial-seat:';

    private const string COUNT_PREFIX = 'audit:denial-count:';

    public function __construct(
        private AuthorizationJournal $journal,
        private CurrentAuditContext $context,
        private Cache $cache,
        private int $windowSeconds,
    ) {}

    public function recordScopeDenial(string $dataset, ?string $employeeUuid, array $context = []): void
    {
        if ($this->windowSeconds < 1) {
            // Ventana desactivada: cada denegacion deja su asiento. Es la
            // configuracion que quiere un cliente que prefiera la fila a la
            // contencion, y la que usa la prueba del escenario Gherkin.
            $this->journal->recordScopeDenial($dataset, $employeeUuid, $context);

            return;
        }

        $key = $this->keyFor($dataset);

        // El contador vive mas que la ventana a proposito: tiene que sobrevivir al
        // hueco entre que el asiento suelta el asiento y llega la denegacion que
        // abre la ventana siguiente.
        $this->cache->add(self::COUNT_PREFIX.$key, 0, $this->windowSeconds * 4);

        $pending = $this->cache->increment(self::COUNT_PREFIX.$key);
        $pending = \is_int($pending) ? $pending : 1;

        // `add()` es la operacion atomica que reparte el asiento: solo una de N
        // peticiones simultaneas se lo lleva. Un `has()` seguido de un `put()`
        // dejaria pasar a todas las que entraran a la vez, que es precisamente el
        // caso que hay que acotar.
        if (! $this->cache->add(self::SEAT_PREFIX.$key, true, $this->windowSeconds)) {
            return;
        }

        $this->cache->forget(self::COUNT_PREFIX.$key);

        $this->journal->recordScopeDenial($dataset, $employeeUuid, [
            ...$context,
            // Cuantas denegaciones representa este asiento, esta incluida. `1`
            // significa «una sola vez», que es el caso normal de quien se equivoca
            // de enlace.
            'repeated_since_last_entry' => $pending,
        ]);
    }

    /**
     * Clave estable del par actor + dataset.
     *
     * El actor sale del contexto de la peticion —quien intenta acceder no puede
     * declarar quien es— y se usa su forma canonica, que es tipo mas
     * identificador y **nunca** un nombre (regla dura 21). Va por `sha1` porque la
     * clave viaja a Redis en produccion: no hace falta que un identificador de
     * cuenta figure en el espacio de claves de la cache, y el hash no tiene que
     * resistir un ataque —solo evitar colisiones entre dos pares.
     */
    private function keyFor(string $dataset): string
    {
        return sha1($this->context->actor()->canonical().'|'.$dataset);
    }
}
