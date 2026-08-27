<?php

declare(strict_types=1);

/*
 * El borde del quiosco — doc 02 §6 (protocolo offline) y §7.1 (limitacion de
 * tasa en la capa de Aplicacion).
 *
 * TODO LO DE AQUI ES CONFIGURACION, NO CONSTANTES (regla dura 13, ADR-017). Un
 * hotel con veinte quioscos y otro con dos no tienen el mismo techo razonable, y
 * cambiarlo no puede exigir tocar el repositorio ni abrir una rama por cliente.
 *
 * POR QUE HAY LIMITES AQUI SI YA LOS HAY EN NGINX. Son dos capas distintas del
 * §7.1 y ninguna sustituye a la otra:
 *
 *   - **Nginx** limita por ORIGEN (600 r/m desde `KIOSK_VLAN_CIDR`, 30 r/m desde
 *     fuera). No sabe que token trae la peticion, asi que no puede distinguir un
 *     quiosco averiado de otro sano cuando los dos salen por la misma IP — que es
 *     lo normal en un hotel.
 *   - **Esta capa** limita por DISPOSITIVO, que es lo que RS-02 exige por escrito
 *     («por dispositivo, por credencial y por IP»). Un quiosco con un bucle
 *     defectuoso no puede consumir la cuota de los demas.
 *
 * El limite por IP se conserva ademas del de dispositivo —RS-02 lo enumera— y se
 * fija al mismo valor que la zona interna de Nginx: quien esta autenticado no
 * deberia encontrarse antes el techo de la aplicacion que el del borde, porque
 * entonces el del borde no mediria nada.
 */

return [

    /*
     * Limites de la capa de Aplicacion, en peticiones por minuto. Se aplican
     * DESPUES de autenticar, asi que la clave es el dispositivo del token; el
     * trafico sin autenticar lo para Nginx, que es donde corresponde.
     */
    'rate_limits' => [

        /*
         * `POST /api/v1/scan`, por dispositivo.
         *
         * Una tablet no puede leer dos codigos QR en el mismo segundo: 120 r/m
         * son dos por segundo, mas de lo que el hardware da de si, y aun asi un
         * techo real si algo se descontrola. El drenaje de la cola offline NO
         * pasa por aqui: usa `/scan/batch`, que lleva cincuenta por peticion.
         */
        'scan_per_device' => (int) env('KIOSK_SCAN_RATE_PER_DEVICE', 120),

        /*
         * `POST /api/v1/scan/batch`, por dispositivo.
         *
         * Sesenta lotes por minuto son 3.000 fichajes por minuto desde un solo
         * quiosco: una tablet que estuvo un dia entero sin red drena su cola en
         * segundos y sigue sobrando margen (regla dura 19: el quiosco nunca
         * bloquea al empleado, y una cola que no drena es eso mismo con retraso).
         */
        'batch_per_device' => (int) env('KIOSK_BATCH_RATE_PER_DEVICE', 60),

        /*
         * `POST /api/v1/scan/pin`, por dispositivo (RF-AT-11, RS-12).
         *
         * DOS ORDENES DE MAGNITUD POR DEBAJO DE `/scan`, Y NO ES UN DESCUIDO.
         * Aqui no se frena un ritmo de fichaje sino FUERZA BRUTA sobre un
         * espacio de 10^6: una persona teclea un codigo de empleado y seis
         * digitos en decenas de segundos, y diez intentos por minuto en un mismo
         * quiosco ya cubren a una cola de gente que ha olvidado la tarjeta el
         * mismo dia —un escenario que, si se da, es un problema de emision de
         * tarjetas, no de este limite—.
         *
         * NO SUSTITUYE AL BLOQUEO POR EMPLEADO del §7.5: este cuenta peticiones
         * por dispositivo y aquel cuenta fallos por persona. Quien prueba PIN
         * desde cinco quioscos esquiva este limite y no el otro.
         */
        'pin_scan_per_device' => (int) env('KIOSK_PIN_SCAN_RATE_PER_DEVICE', 10),

        /*
         * `POST /api/v1/scan/pin`, por IP.
         *
         * PROPIO Y MAS ESTRECHO QUE `per_ip`, al contrario que en las demas
         * zonas. En el resto del camino del quiosco el techo por IP se iguala al
         * del borde para que mande Nginx; aqui la pregunta es otra —«¿cuantos
         * PIN se pueden probar por minuto desde un sitio?»— y heredar los 600
         * generales habria dejado este control sin efecto practico. El §7.5 lo
         * exige como control INDEPENDIENTE del bloqueo por empleado.
         *
         * Sesenta por minuto cubren de sobra a un hotel entero cuyos quioscos
         * salgan por la misma IP: el fichaje por PIN es la excepcion, no la
         * norma. Si un cliente lo alcanza de verdad, lo que hay que mirar es
         * `pin_fallback_scans_total`, no este numero.
         */
        'pin_scan_per_ip' => (int) env('KIOSK_PIN_SCAN_RATE_PER_IP', 60),

        /*
         * `GET /api/v1/kiosk/roster` y `POST /api/v1/kiosk/heartbeat`, por
         * dispositivo.
         *
         * El latido va cada minuto y el padron se refresca unas pocas veces al
         * dia: 60 r/m es dos ordenes de magnitud por encima del uso legitimo y
         * sigue frenando a un cliente que se atasque reintentando.
         */
        'telemetry_per_device' => (int) env('KIOSK_TELEMETRY_RATE_PER_DEVICE', 60),

        /*
         * Techo por IP de todo el camino del quiosco, para satisfacer la tercera
         * clave que enumera RS-02.
         *
         * Es el mismo valor que la zona interna de Nginx (§7.1) a proposito:
         * todos los quioscos de un hotel pueden compartir salida, asi que este
         * numero tiene que cubrir la instalacion entera. Bajarlo por debajo de
         * los 600 del borde convertiria este limite en el que de verdad manda, y
         * el sintoma seria «el quiosco va lento a las 06:00».
         */
        'per_ip' => (int) env('KIOSK_RATE_PER_IP', 600),
    ],

    /*
     * Tamano maximo de un lote de sincronizacion (doc 02 §6: «lotes de 50»).
     *
     * Se declara aqui **y** en el contrato OpenAPI (`ScanBatchRequest.maxItems`)
     * porque OpenAPI no puede referenciar codigo. Una prueba ata los dos valores;
     * si alguien sube uno y olvida el otro, el contrato y el servidor dirian
     * cosas distintas y el cliente generado dejaria de proteger nada.
     */
    'batch_max_size' => (int) env('KIOSK_BATCH_MAX_SIZE', 50),

];
