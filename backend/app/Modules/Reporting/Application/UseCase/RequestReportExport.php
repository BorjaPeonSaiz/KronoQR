<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Command\RequestReportExportCommand;
use App\Modules\Reporting\Application\Port\ReportExportQueue;
use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\Port\ReportingEventPublisher;
use App\Modules\Reporting\Domain\Event\ReportExportRequested;
use App\Modules\Reporting\Domain\Exception\ReportExportAlreadyInProgress;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Pide un informe en diferido: crea la fila, deja el asiento y encola el trabajo
 * (**RF-IN-06**, RS-05, ficha 3.9).
 *
 * ## Lo que este caso de uso NO hace
 *
 * No consulta nada del registro horario. Termina en milisegundos y devuelve la
 * fila en `pending`, que es lo que el contrato promete en el `202`. La
 * generacion cruza la plantilla con el calendario durante minutos y por eso
 * existe esta tarea: el informe sincrono responde `422` cuando no cabe, y ese
 * `422` remite aqui.
 *
 * ## El asiento se escribe al PEDIRLO, dentro de la transaccion
 *
 * Y no cuando termina. Son dos hechos distintos y pueden no coincidir: entre uno
 * y otro esta la cola, y una generacion puede fallar. Sin este asiento, un
 * intento de sacar las horas nominales de la plantilla entera que revienta al
 * minuto no dejaria ningun rastro — y esa intencion es justo lo que busca una
 * revision de accesos masivos a datos personales (RS-05).
 *
 * Sincrono y dentro de la transaccion: si el asiento no se puede escribir, la
 * fila no se crea (regla dura 6, ADR-027).
 *
 * ## Se encola DESPUES de confirmar
 *
 * Un trabajador que arrancara antes del `COMMIT` no encontraria la fila y se
 * declararia fallido sin motivo. Con la cola `sync` —configuracion legitima de
 * una instalacion pequeña— el efecto seria peor todavia: la generacion entera
 * ocurriria dentro de la transaccion de la peticion.
 *
 * ## `409` sin consultar antes
 *
 * {@see ReportExportAlreadyInProgress} la lanza el repositorio cuando el
 * `INSERT` choca con el indice unico parcial, no un `SELECT` previo: con dos
 * pestañas pulsando el boton a la vez, la comprobacion en PHP dejaria pasar las
 * dos. Y el limite es **por persona** (decision 2): dos responsables generando a
 * la vez no se estorban.
 *
 * ## La licencia no se comprueba aqui
 *
 * Se comprueba **al pedir**, en el controlador, porque es ahi donde la
 * degradacion tiene que verse (`402`, decision 6). Un trabajo ya encolado
 * termina aunque la licencia caduque entre medias: un fichero a medias no le
 * sirve a nadie, y borrarlo por una fecha del fabricante seria castigar a quien
 * ya habia pedido el informe.
 */
final readonly class RequestReportExport
{
    public function __construct(
        private ReportExportRepository $exports,
        private ReportExportQueue $queue,
        private ReportingEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        /**
         * Segundos tras los cuales un informe sin terminar se declara atascado.
         * Ya resuelto por quien construye (regla dura 14): el caso de uso no
         * consulta la configuracion, y asi una prueba puede fijar un segundo sin
         * tocar el estado global del proceso.
         */
        private int $staleAfterSeconds,
    ) {}

    /**
     * @throws ReportExportAlreadyInProgress si esa cuenta ya tiene una `pending` o `running`
     */
    public function handle(RequestReportExportCommand $command): ReportExport
    {
        $now = $this->clock->now();

        /*
         * UUID v7 como el resto de los identificadores publicos del producto:
         * ordenado en el tiempo —el indice de la clave publica no se fragmenta—
         * y sin decir cuantos informes se han pedido. Aqui ademas **es la mitad
         * del secreto de la ruta de descarga**, junto con el token: ~74 bits
         * aleatorios del `uuid` v7 —48 de sus 122 son marca de tiempo y por tanto
         * adivinables— mas los 256 bits del token, y un solo uso.
         */
        $uuid = Str::uuid7()->toString();

        /*
         * SE DESATASCA ANTES DE INTENTAR CREAR.
         *
         * El indice unico parcial solo admite una fila `pending|running` por
         * cuenta, asi que un informe que nadie va a terminar deja a esa persona
         * con `409` **para siempre**. Y quedarse a medias es facil: el trabajador
         * de cola muere, o alguien para los contenedores, que es el paso 1 de
         * cualquier actualizacion.
         *
         * Se barre aqui ademas de en la purga diaria porque quien acaba de pulsar
         * el boton no puede esperar a mañana para desbloquearse. Es un `UPDATE`
         * acotado por indice sobre una tabla que casi nunca tiene filas en curso.
         *
         * **Fuera de la transaccion de creacion** a proposito: son dos hechos
         * independientes, y si el `INSERT` fallara luego la fila atascada tiene
         * que quedar marcada igual.
         */
        $this->exports->failStale($now->modify('-'.max(1, $this->staleAfterSeconds).' seconds'), $now);

        try {
            /** @var ReportExport $export */
            $export = $this->connection->transaction(function () use ($uuid, $command, $now): ReportExport {
                $created = $this->exports->create(
                    uuid: $uuid,
                    kind: $command->kind,
                    format: $command->format,
                    parameters: $command->parameters,
                    scope: $command->scope,
                    // Vacios al pedirlo: los criterios describen el informe que se
                    // genero —cuantos festivos tenia el periodo, si se incluyeron
                    // los turnos abiertos— y eso no se sabe hasta ejecutarlo.
                    criteria: [],
                    requestedByUserId: $command->requestedByUserId,
                    requestedAt: $now,
                );

                $this->events->publish(new ReportExportRequested(
                    uuid: $created->uuid,
                    kind: $created->kind->value,
                    format: $created->format,
                    parameters: $created->parameters->toArray(),
                    // El alcance con el que se autorizo (RF-ID-03), como en el
                    // asiento del informe sincrono: distingue «RRHH pidio el hotel
                    // entero» de «un responsable pidio su cocina». Nunca la lista
                    // de identificadores.
                    // `?->` porque el alcance es nulable en el modelo: se borra al
                    // purgar (RL-11). Una fila recien creada no puede estarlo —lo
                    // garantiza el `CHECK` `report_exports_chk_purged_is_minimised`—
                    // y aun asi el caso imposible cae del lado seguro:
                    // `departments`, que es el alcance mas estrecho.
                    scope: $created->scope?->isUnrestricted() === true ? 'all' : 'departments',
                    requestedByUserId: $created->requestedByUserId,
                    occurredAt: $now,
                ));

                return $created;
            });
        } catch (ReportExportAlreadyInProgress) {
            /*
             * **La que ocupa el turno se relee AQUI y no en el repositorio.**
             *
             * El choque contra el indice unico deja la transaccion abortada, asi
             * que un `SELECT` dentro de ella fallaria con `25P02` y el cliente
             * recibiria un `500` en vez del `409` que le dice que espere. Al
             * salir de `transaction()` el motor ya ha deshecho.
             *
             * Puede volver nula si esa exportacion termino entre el choque y esta
             * linea: el `409` sigue siendo correcto —esta peticion no llego a
             * crearse— y el borde lo dice con otras palabras.
             */
            throw new ReportExportAlreadyInProgress(
                $this->exports->inProgressFor($command->requestedByUserId),
            );
        }

        $this->queue->enqueue($export->uuid);

        return $export;
    }
}
