<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Command\RequestDataExportCommand;
use App\Modules\Product\Application\Port\DataExportQueue;
use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Domain\Event\DataExportRequested;
use App\Modules\Product\Domain\Exception\DataExportAlreadyInProgress;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Pide la exportacion integra: crea la fila, deja el asiento y encola el trabajo
 * (**RF-PD-14**, RL-20, RS-05).
 *
 * ## Lo que este caso de uso NO hace
 *
 * No genera nada. Termina en milisegundos y devuelve la fila en `pending`, que
 * es lo que el contrato promete en el `202`. La generacion recorre todas las
 * tablas de la instalacion y no cabe en una peticion HTTP (decision 4 de la
 * ficha 5.10).
 *
 * ## El asiento se escribe al PEDIRLA, dentro de la transaccion
 *
 * Y no cuando termina. Son dos hechos distintos y pueden no coincidir: entre uno
 * y otro esta la cola, y una generacion puede fallar. Sin este asiento, un
 * intento de llevarse una copia completa de la plantilla que revienta al minuto
 * no dejaria ningun rastro — y esa intencion es justo lo que busca una revision
 * de accesos masivos a datos personales (RS-05).
 *
 * Sincrono y dentro de la transaccion, como el resto de asientos de `Product`:
 * si el asiento no se puede escribir, la fila no se crea (regla dura 6).
 *
 * ## Se encola DESPUES de confirmar, y solo desde el panel
 *
 * Despues, porque un trabajador que arrancara antes del `COMMIT` no encontraria
 * la fila y se declararia fallido sin motivo. Y solo desde el panel porque desde
 * la consola la generacion es sincrona: quien esta delante de la terminal quiere
 * ver la ruta del fichero antes de irse, y ahi no hay ningun tiempo de espera
 * que agotar.
 *
 * ## `409` sin consultar antes
 *
 * {@see DataExportAlreadyInProgress} la lanza el repositorio cuando el `INSERT`
 * choca con el indice unico parcial, no un `SELECT` previo: con dos pestañas
 * pulsando el boton a la vez, la comprobacion en PHP dejaria pasar las dos.
 *
 * ## No consulta la licencia (regla dura 15, ADR-019)
 *
 * Ni aqui ni en ninguno de los otros cuatro casos de uso de la exportacion.
 * RL-20 es la garantia de continuidad del cliente *«aunque la relacion comercial
 * termine»*: una exportacion bloqueada por licencia caducada seria exactamente
 * lo que ADR-019 prohibe, y ademas dejaria al cliente sin acceso a datos que
 * esta obligado a conservar cuatro años.
 */
final readonly class RequestDataExportHandler
{
    public function __construct(
        private DataExportRepository $exports,
        private DataExportQueue $queue,
        private ProductEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        /**
         * Segundos tras los cuales una exportacion sin terminar se declara
         * atascada. Ya resuelto por quien construye (regla dura 14): el caso de
         * uso no consulta la configuracion, y asi una prueba puede fijar un
         * segundo sin tocar el estado global del proceso.
         */
        private int $staleAfterSeconds,
    ) {}

    /**
     * @throws DataExportAlreadyInProgress si ya hay una `pending` o `running`
     */
    public function handle(RequestDataExportCommand $command): DataExport
    {
        $now = $this->clock->now();

        /*
         * UUID v7 como el resto de los identificadores publicos del producto:
         * ordenado en el tiempo —el indice de la clave publica no se fragmenta—
         * y sin decir cuantas exportaciones se han pedido.
         */
        $uuid = Str::uuid7()->toString();

        /*
         * SE DESATASCA ANTES DE INTENTAR CREAR, y esto es lo que impide que un
         * apagon deje al cliente sin poder ejercer RL-20.
         *
         * El indice unico parcial solo admite una fila `pending|running`, asi que
         * una exportacion que nadie va a terminar bloquea todas las siguientes
         * **para siempre**: `409` eterno. Y quedarse a medias es facil —el
         * trabajador de cola muere, o alguien para los contenedores, que es el
         * paso 1 de cualquier actualizacion—.
         *
         * Se barre aqui ademas de en la purga horaria porque quien acaba de
         * pulsar el boton no puede esperar a la hora en punto para que el
         * producto se desbloquee solo. Es un `UPDATE` acotado por indice: sobre
         * una tabla que casi siempre no tiene ninguna fila en curso, no cuesta
         * nada.
         *
         * **Fuera de la transaccion de creacion** a proposito: son dos hechos
         * independientes, y si el `INSERT` fallara luego por cualquier motivo la
         * fila atascada tiene que quedar marcada igual.
         */
        $this->exports->failStale($now->modify('-'.max(1, $this->staleAfterSeconds).' seconds'), $now);

        try {
            /** @var DataExport $export */
            $export = $this->connection->transaction(function () use ($uuid, $command, $now): DataExport {
                $created = $this->exports->create(
                    uuid: $uuid,
                    requestedVia: $command->requestedVia,
                    requestedByUserId: $command->requestedByUserId,
                    requestedAt: $now,
                );

                $this->events->publish(new DataExportRequested(
                    uuid: $created->uuid,
                    requestedVia: $created->requestedVia,
                    requestedByUserId: $command->requestedByUserId,
                    occurredAt: $now,
                ));

                return $created;
            });
        } catch (DataExportAlreadyInProgress) {
            /*
             * **La que ocupa el turno se relee AQUI y no en el repositorio.**
             *
             * El choque contra el indice unico deja la transaccion abortada, asi
             * que un `SELECT` dentro de ella fallaria con `25P02` y el cliente
             * recibiria un `500` en vez del `409` que le dice que espere. Al
             * salir de `transaction()` el motor ya ha deshecho, y aqui se puede
             * consultar con normalidad.
             *
             * Puede volver nula si esa exportacion termino entre el choque y
             * esta linea: el `409` sigue siendo correcto —esta peticion no llego
             * a crearse— y el borde lo dice con otras palabras.
             */
            throw new DataExportAlreadyInProgress($this->exports->inProgress());
        }

        if ($command->requestedVia === DataExportOrigin::Panel) {
            $this->queue->enqueue($export->uuid);
        }

        return $export;
    }
}
