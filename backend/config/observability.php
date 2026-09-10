<?php

declare(strict_types=1);

/*
 * Observabilidad (doc 02 §8).
 *
 * POR QUE LAS METRICAS DE LA FASE 1 SE ESCRIBEN EN FICHEROS
 *
 * `/metrics` lo expone la aplicacion a partir de la tarea 3.1, con
 * `promphp/prometheus_client_php`. Hasta entonces hay metricas que ya existen y
 * que no pueden esperar —el resultado de la copia (tarea 1.18) y el de la
 * verificacion de la cadena de auditoria (tarea 1.14)—, y las dos las produce un
 * proceso que corre una vez al dia y termina.
 *
 * Un contador en memoria de un proceso que termina no lo lee nadie. La via que
 * ya esta montada es el colector *textfile* de `node-exporter`
 * (infra/compose.*.yaml, §8.2): cada productor escribe SU fichero `.prom` en
 * este directorio y Prometheus lo recoge. Es exactamente el mecanismo que usan
 * `infra/scripts/backup.sh` y `restore-drill.sh`, y por la misma razon: la
 * metrica tiene que seguir publicandose aunque la aplicacion no arranque.
 *
 * La ruta coincide con `BACKUP_PATH/metrics` porque es el volumen que
 * `node-exporter` monta. No es una dependencia de las copias: es el mismo punto
 * de recogida.
 */

return [

    'metrics' => [

        /*
         * Directorio del colector textfile. Cada productor escribe su propio
         * fichero: dos ficheros que declaren la misma metrica hacen que
         * node-exporter descarte los dos.
         */
        'textfile_path' => env(
            'METRICS_TEXTFILE_PATH',
            env('BACKUP_PATH', '/var/backups/fichaje').'/metrics',
        ),

        /*
         * Si se escriben. En la suite de pruebas se apaga: lo que hay que
         * comprobar es que el caso de uso llama al puerto, y eso se verifica con
         * un doble, no con un fichero en disco.
         */
        'enabled' => env('METRICS_TEXTFILE_ENABLED', true),

        /*
         * Quien puede leer `GET /metrics` (doc 02 §8.1, doc 01 Anexo B, RS-09).
         *
         * ES LA MISMA VARIABLE QUE USA NGINX en su bloque `geo`
         * (infra/docker/nginx/templates/kronoqr.conf.template), y eso es
         * deliberado: la restriccion del borde y la de la aplicacion tienen que
         * decir lo mismo o una de las dos miente. Nginx es la primera guarda;
         * `RestrictToMetricsNetwork` es la segunda, para que una plantilla mal
         * editada no deje las series a la vista.
         *
         * Se admiten varios rangos separados por coma o espacio —Nginx solo
         * toma uno—, en IPv4 o IPv6, con o sin prefijo.
         *
         * EL VALOR POR OMISION ES EL BUCLE LOCAL, no «todo». Una instalacion
         * que se olvide de configurarlo se queda sin metricas, que es un fallo
         * ruidoso y reparable; el defecto contrario publicaria la operacion del
         * hotel a quien pasara por delante.
         */
        'allow_cidr' => env('METRICS_ALLOW_CIDR', '127.0.0.1/32 ::1/128'),
    ],

    /*
     * A QUIEN LLEGAN LAS ALERTAS (doc 01 §9.3, tarea 3.2, decisiones 5 y 15).
     *
     * QUIEN LAS ENVIA NO ES LA APLICACION. Las evalua Prometheus y las reparte
     * Alertmanager, que lee estas mismas variables del `.env` a traves de
     * `infra/observability/alertmanager/render-config.sh`. La aplicacion no
     * manda ni un correo de alerta y no debe: el dia que interese saber si la
     * copia fallo o si el borde no responde puede ser justo el dia en que la
     * aplicacion no arranca.
     *
     * ENTONCES, ¿POR QUE ESTAN AQUI? Porque `product:doctor` es donde el IT del
     * cliente mira, y una instalacion con el perfil de observabilidad encendido
     * y los tres buzones vacios es **peor que una sin alertas**: se cree
     * vigilada. Alertmanager no puede avisar de eso —un destino vacio
     * simplemente no genera entrega— y nadie mas lo mira.
     *
     * NACEN VACIAS y con la marca `[CLIENTE]` en `.env.example` (regla dura 13):
     * el buzon de informatica de un hotel no vive en este repositorio.
     */
    'alerting' => [

        /*
         * Los perfiles de Docker Compose activos en la instalacion. Con
         * `observability` dentro, el `.env` levanta Prometheus, Alertmanager,
         * Grafana y las sondas; sin el, no hay nada que enrutar y la
         * comprobacion de `doctor` no tiene sentido.
         *
         * Es una variable del ANFITRION que el `.env` comparte con el
         * contenedor (`env_file` en `infra/compose.*.yaml`): se lee, no se
         * escribe.
         */
        'profiles' => env('COMPOSE_PROFILES', ''),

        /*
         * Un buzon por destinatario, exactamente los tres que admite la etiqueta
         * `destinatario` de las reglas. El envio va por el SMTP del cliente, el
         * mismo de `MAIL_*`.
         */
        'email' => [
            'it' => env('ALERT_EMAIL_IT', ''),
            'hr' => env('ALERT_EMAIL_RRHH', ''),
            'security' => env('ALERT_EMAIL_SEGURIDAD', ''),
        ],

        /*
         * El otro camino de entrega del mismo destinatario: un webhook del
         * cliente (su chat corporativo, su sistema de tickets). Opcional, y
         * ALTERNATIVO al correo a efectos de esta lectura: un destinatario con
         * webhook y sin buzon **si** recibe sus alertas.
         *
         * SON SECRETOS PORTADORES: quien tiene la URL puede escribir en ese
         * canal. Alertmanager las recibe por `url_file` con permisos 0600 y
         * **aqui solo se mira si estan puestas**, nunca su valor: esta lectura
         * la consume `product:doctor`, cuyo informe viaja en el paquete de
         * diagnostico (ADR-020).
         */
        'webhook' => [
            'it' => env('ALERT_WEBHOOK_IT', ''),
            'hr' => env('ALERT_WEBHOOK_RRHH', ''),
            'security' => env('ALERT_WEBHOOK_SEGURIDAD', ''),
        ],
    ],

    /*
     * QUE PROXIES PUEDE CREERSE LA APLICACION (RS-02, RS-09, RS-12, regla dura 13).
     *
     * Lista de direcciones o rangos CIDR separados por coma o espacio. **VACIA DE
     * SERIE, y ese es el valor correcto para el despliegue del producto**: Nginx
     * habla con PHP por FastCGI y `fastcgi_params` pasa `REMOTE_ADDR` con la
     * direccion del cliente de verdad, asi que no hay ningun salto del que
     * recuperar la IP original.
     *
     * Con la lista vacia, `App\Http\Middleware\TrustProxies` no declara ningun
     * proxy y `$request->ip()` es `REMOTE_ADDR`: `X-Forwarded-For` no se lee.
     * Eso es lo que sostiene tres garantias distintas —el `403` de `/metrics`
     * (RS-09), la IP que consta en `audit_log` y los limites por IP de los
     * intentos de autenticacion (RS-12)—, que con la cabecera de por medio se
     * falsifican enviando dos lineas.
     *
     * SE PONE SOLO SI HAY OTRO BALANCEADOR POR DELANTE DE NGINX (un HAProxy del
     * cliente, el balanceador de su organizacion), y entonces con la direccion
     * exacta de ese equipo. Nunca `*`: confiar en cualquiera es no confiar en
     * nadie.
     *
     * NO VIAJA EN EL PAQUETE DE DIAGNOSTICO a proposito (ADR-020): describe la
     * topologia de red interna del cliente.
     */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

];
