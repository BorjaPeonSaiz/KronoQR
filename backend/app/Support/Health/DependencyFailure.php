<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * Una dependencia que no responde: **cual**, y **de que tipo fue el fallo**.
 *
 * Ninguno de los dos campos sale en la respuesta de `GET /api/v1/ready`, que es
 * publica y no autenticada. Una sonda que enumera los servicios caidos es un
 * mapa gratuito de la instalacion; el diagnostico por componente es del
 * administrador (RF-PD-09, RF-PD-13).
 *
 * ## Por que `failure` es la CLASE y ya no el mensaje
 *
 * Aqui viajaba `$exception->getMessage()`, y de ahi iba al log. Un mensaje de
 * conexion de PDO lleva host, puerto y **usuario de base de datos**
 * (`SQLSTATE[08006] … user "fichaje_app" … host "postgres" port 5432`), y desde
 * la tarea 3.1 el log tecnico no se queda en el fichero del servidor: se empuja
 * a Loki y se conserva 90 dias (RL-11). Publicar la topologia y las credenciales
 * de conexion en un almacen consultable, cada pocos segundos mientras dura una
 * averia, no es lo que hace falta para diagnosticarla.
 *
 * Con el componente y la clase de la excepcion
 * —`database` + `Illuminate\Database\QueryException`, `redis` +
 * `RedisException`— se distingue «no hay ruta al host» de «credenciales
 * rechazadas» de «demasiadas conexiones», que es lo que la sonda tiene que
 * decir. El mensaje completo sigue estando donde toca: en el log de PostgreSQL y
 * en el paquete de diagnostico (RF-PD-09).
 */
final readonly class DependencyFailure
{
    public function __construct(
        public string $component,
        /** La CLASE de la excepcion, nunca su mensaje. Ver el docblock. */
        public string $failure,
    ) {}
}
