<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorEventPage;
use App\Modules\Product\Domain\ValueObject\ErrorEventSummary;
use App\Modules\Product\Domain\ValueObject\ErrorFingerprint;
use App\Modules\Product\Domain\ValueObject\ErrorWriteOutcome;
use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use DateTimeImmutable;

/**
 * La tabla `error_events`, vista desde los casos de uso (RF-PD-15).
 *
 * ## Cinco operaciones y ni una mas
 *
 * Escribir agrupando, listar, resolver, purgar y resumir. No hay `delete(id)`,
 * no hay `update(id, …)` y no hay `find(id)` suelto: **nadie edita un error**.
 * Lo unico que una persona puede hacerle a una fila de aqui es darla por
 * atendida, y eso es {@see self::resolve()}.
 *
 * ## `upsert()` recibe lo saneado, no lo crudo
 *
 * El `ErrorReport` trae el mensaje tal y como lo produjo la excepcion o el
 * cliente; el mensaje y el contexto que se guardan llegan **aparte y ya
 * saneados**, junto con la huella calculada sobre ellos. La razon de que sean
 * parametros distintos y no un objeto ya montado: quien decide que se puede
 * guardar es el caso de uso `RecordErrorEvent` —nombrado en prosa y no con
 * `@see`, porque un puerto no puede depender de la capa que lo usa y Deptrac lo
 * verifica—, en un solo sitio. Un adaptador que recibiera el informe crudo
 * tendria que saber sanear, y habria dos sitios donde equivocarse con la regla
 * dura 21.
 *
 * ## `upsert()` devuelve `bool` y no lanza
 *
 * Es el contrato de {@see ErrorEventSink} y
 * llega hasta aqui: un error al guardar el error no puede convertirse en un
 * segundo error (regla dura 19). Las otras cuatro **si lanzan**: se ejecutan
 * desde una pantalla o desde un comando, donde un fallo silencioso seria un
 * historico que parece vacio.
 */
interface ErrorEventRepository
{
    /**
     * Escribe el grupo o incrementa el que ya existe, por huella.
     *
     * `INSERT … ON CONFLICT (fingerprint) DO UPDATE`: **una sentencia**, sin
     * `SELECT` previo. Cientos de errores simultaneos en un cambio de turno es
     * el caso normal y no el excepcional, y cualquier comprobacion previa
     * dejaria pasar la carrera.
     *
     * **Un grupo resuelto que vuelve a ocurrir se reabre** conservando su
     * recuento: `resolved_at` y `resolved_by_user_id` vuelven a nulo.
     *
     * **Se reabre solo si la ocurrencia es POSTERIOR a `resolved_at`**: un
     * reporte que llega tarde —la cola offline de una tablet que estuvo el fin
     * de semana sin cobertura— describe algo que paso antes de que alguien lo
     * diera por atendido, y reabrir con el seria contarle a IT que su arreglo no
     * funciono cuando lo que llego es historia.
     *
     * @param  string  $message  Mensaje ya saneado.
     * @param  array<string, scalar>  $context  Contexto ya filtrado por la lista de permitidos.
     * @param  DateTimeImmutable  $seenAt  Cuando ocurrio, ya acotado por los dos extremos.
     * @param  DateTimeImmutable  $recordedAt  Reloj del servidor al recibirlo.
     * @return ErrorWriteOutcome Si se creo, si se repitio o si no se pudo, SIN LANZAR.
     */
    public function upsert(
        ErrorReport $report,
        ErrorFingerprint $fingerprint,
        string $message,
        array $context,
        DateTimeImmutable $seenAt,
        DateTimeImmutable $recordedAt,
    ): ErrorWriteOutcome;

    /**
     * Cuantos grupos ABIERTOS tiene un origen (decision 14).
     *
     * Lo consulta el sumidero antes de crear una huella nueva: por encima del
     * techo, la ocurrencia va al grupo de desbordamiento del origen en lugar de
     * abrir fila. Se cuentan solo los abiertos porque el techo protege de la
     * entropia viva, no del historico: un origen con seiscientos grupos ya
     * atendidos no tiene por que dejar de registrar el que aparezca hoy.
     */
    public function countOpenGroups(ErrorSource $source): int;

    /**
     * Si esa huella ya tiene fila.
     *
     * **Solo se pregunta cuando el origen esta en el techo**, y por eso su
     * condicion de carrera no importa: sirve para decidir si una ocurrencia
     * incrementa un grupo que ya existe —que siempre debe poder hacerlo— o si
     * crearia uno nuevo. Si dos procesos la respondieran a la vez, el peor
     * desenlace es un grupo de mas por encima del techo.
     */
    public function exists(ErrorFingerprint $fingerprint): bool;

    /**
     * Una pagina del historico, ordenada por `last_seen_at` descendente, con los
     * dos recuentos de abiertos **sin filtrar** de la cabecera.
     */
    public function page(ErrorEventQuery $query): ErrorEventPage;

    /**
     * Da un grupo por atendido y devuelve la fila resultante.
     *
     * **Idempotente**: resolver uno ya resuelto no cambia nada y devuelve la
     * misma fila con su autor y su instante originales. La accion no tiene
     * segundo efecto y no merece un `409`.
     *
     * @return ErrorEvent|null Nulo si el identificador no existe, para que el
     *                         controlador responda `404`.
     */
    public function resolve(int $id, int $userId, DateTimeImmutable $at): ?ErrorEvent;

    /**
     * Borra los grupos cuyo `last_seen_at` sea anterior al corte y devuelve
     * cuantos se fueron.
     *
     * **Borra de verdad**, al contrario que casi todo en este producto (regla
     * dura 5): esto no es el registro horario ni `audit_log`, es diagnostico
     * tecnico con 90 dias de vida (`ERROR_HISTORY_RETENTION_DAYS`, RL-11).
     *
     * Por `last_seen_at` y no por `first_seen_at`: lo que se conserva 90 dias es
     * un grupo **vivo**, y uno que sigue ocurriendo cada dia no vence porque su
     * primera aparicion sea antigua.
     *
     * **Por lotes**, igual que el ciclo corto de retencion de `Compliance`: un
     * `DELETE` con noventa dias de acumulacion detras es una sentencia larga que
     * retiene bloqueos sobre la misma tabla en la que se esta escribiendo cada
     * error que ocurra mientras corre. El bucle acota cada sentencia y suelta
     * entre una y otra.
     *
     * @param  int  $batchSize  Filas por sentencia (`compliance.retention.batch_size`).
     */
    public function pruneOlderThan(DateTimeImmutable $cutoff, int $batchSize): int;

    /**
     * Los recuentos por origen y por nivel del periodo, para el paquete de
     * diagnostico.
     *
     * @param  DateTimeImmutable  $since  Grupos con `last_seen_at` desde este instante.
     */
    public function summary(DateTimeImmutable $since): ErrorEventSummary;
}
