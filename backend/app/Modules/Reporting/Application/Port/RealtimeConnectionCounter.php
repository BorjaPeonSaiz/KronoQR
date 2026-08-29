<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * Cuantos paneles siguen conectados al WebSocket de presencia (`websocket_connections_active`,
 * doc 02 §8.2).
 *
 * **Por que hace falta preguntarlo y no se puede contar aqui.** Reverb corre en
 * **otro proceso** —un contenedor propio del `docker compose`— y no expone
 * metricas de Prometheus. Las conexiones vivas solo las conoce el, y el unico
 * modo de saberlas desde la aplicacion es preguntarselas por su API HTTP
 * compatible con Pusher (`GET /apps/{id}/connections`), firmada con el secreto
 * de aplicacion. Contar suscripciones en la aplicacion seria contar
 * autorizaciones concedidas, que no es lo mismo: un panel autorizado que cerro
 * la pestaña seguiria contando.
 *
 * **`null` cuando no se ha podido preguntar, y nunca `0`.** Son dos hechos
 * distintos: cero significa «nadie tiene el panel abierto» —normal de madrugada—
 * y nulo significa «Reverb no contesta», que es justo la averia que esta metrica
 * existe para detectar (ADR-011: el panel de salud debe distinguir «WebSocket
 * caido» de «sistema caido»). Publicarlos igual convertiria una averia en una
 * jornada tranquila.
 *
 * **No puede tumbar a quien lo llama.** El adaptador atrapa cualquier fallo de
 * red o de firma y devuelve `null`: quien lo usa es una tarea programada de
 * metricas, y una metrica que se cae no puede llevarse por delante a las demas.
 */
interface RealtimeConnectionCounter
{
    public function activeConnections(): ?int;
}
