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
     * Emparejamiento de una tablet (RF-PD-06, tarea 5.6).
     *
     * TODO ESTO ES CONFIGURACION Y NO CONSTANTES (regla dura 13, ADR-017). Un
     * hotel que da de alta sus quioscos con el IT delante y otro que lo hace por
     * telefono con la recepcionista no necesitan la misma ventana, y cambiarla no
     * puede exigir tocar el repositorio.
     *
     * LO QUE PROTEGE EL EMPAREJAMIENTO NO ES LA ENTROPIA DEL CODIGO. Seis digitos
     * se leen de lejos y se teclean a mano, que es para lo que existen; quien
     * impide que un tercero se lleve el quiosco es el SECRETO DE RECOGIDA —32
     * bytes que no salen de la tablet—, la caducidad corta y el hecho de que nada
     * se vincula sin el `confirm` de un `admin`. Subir el codigo a diez digitos
     * no añadiria seguridad y si haria que alguien lo tecleara mal.
     */
    'pairing' => [

        /*
         * Cuanto vive un codigo sin confirmar, en segundos.
         *
         * Diez minutos es lo que tarda una persona en ir del armario de la tablet
         * al ordenador del panel. Mas seria dejar codigos vivos por el hotel;
         * menos convertiria cada alta en una carrera, y la tablet tendria que
         * pedir otro codigo mientras alguien lo esta tecleando.
         *
         * **Acota la espera de la CONFIRMACION, no la recogida.** Una vez
         * confirmada la solicitud, `/kiosk/pair/claim` entrega el token aunque
         * este plazo haya pasado: la fila de `devices` ya existe y negarlo
         * dejaria un quiosco dado de alta que ninguna tablet puede usar — el
         * callejon sin salida que prohibe la regla dura 19.
         */
        'code_ttl_seconds' => (int) env('KIOSK_PAIRING_CODE_TTL_SECONDS', 600),

        /*
         * Cada cuantos segundos sondea la tablet `POST /api/v1/kiosk/pair/claim`.
         *
         * Viaja en la respuesta de `/kiosk/pair` y no compilado en la PWA: el
         * limitador del `claim` se dimensiona a partir de esta cadencia, y si el
         * valor viviera en el cliente, ajustarlo obligaria a reinstalar la
         * aplicacion en cada tablet del hotel.
         *
         * Cinco segundos son 120 sondeos en los diez minutos de vida del codigo:
         * suficiente para que el alta se sienta inmediata y lejos de cualquier
         * techo.
         */
        'poll_interval_seconds' => (int) env('KIOSK_PAIRING_POLL_INTERVAL_SECONDS', 5),

        /*
         * `POST /api/v1/kiosk/pair`, por IP y por minuto (zona
         * `pairing-request`).
         *
         * ES UNA ESCRITURA PUBLICA, asi que el techo no es celo: sin el,
         * cualquiera puede llenar la tabla de solicitudes pendientes y agotar el
         * espacio de codigos de seis digitos, que es la unica forma de negar el
         * alta de un quiosco desde fuera.
         *
         * Diez por minuto y por IP cubren de sobra a un hotel que da de alta
         * varias tablets la misma mañana desde la misma red.
         */
        'request_rate_per_ip' => (int) env('KIOSK_PAIRING_REQUEST_RATE_PER_IP', 10),

        /*
         * `POST /api/v1/kiosk/pair/claim`, por `pairing_id` y por minuto (zona
         * `pairing-claim`).
         *
         * POR SOLICITUD Y NO SOLO POR IP, al contrario que la zona de arriba: un
         * limite por IP no acota nada cuando todos los quioscos de un hotel salen
         * por la misma direccion, y este es ademas el endpoint donde se intentaria
         * adivinar un secreto.
         *
         * Treinta es **el sextuple** de la cadencia de sondeo —doce por minuto—
         * para que un reintento honesto tras un corte de red nunca choque con el
         * techo: la regla dura 19 dice que la tablet no puede quedarse atrapada, y
         * un `429` en el ultimo sondeo de un emparejamiento ya confirmado seria
         * exactamente eso. El techo por IP de esta zona es el general del quiosco
         * (`rate_limits.per_ip`), igual que el del borde.
         */
        'claim_rate_per_pairing' => (int) env('KIOSK_PAIRING_CLAIM_RATE_PER_PAIRING', 30),

        /*
         * Cuantas solicitudes **vivas** —pendientes y sin caducar— admite la
         * instalacion a la vez.
         *
         * ES UN CONTROL DE RECURSOS, NO DE NEGOCIO, y complementa al limitador
         * por IP en lo que aquel no puede hacer: el techo por origen frena a UNO,
         * y quien reparta el trafico entre direcciones lo esquiva. Esta cota mira
         * el conjunto, que es lo que de verdad protege el espacio de codigos de
         * seis digitos y la tabla.
         *
         * Veinte es un orden de magnitud por encima del uso real: una instalacion
         * es un hotel (ADR-040) y se dan de alta unos pocos quioscos, casi
         * siempre de uno en uno. Superarla responde `503`, no `429`: no es «vas
         * demasiado rapido» sino «ahora mismo no puedo atenderte», y la tablet
         * reintenta sola (regla dura 19). El hueco aparece solo, porque cada
         * peticion purga antes las pendientes ya caducadas.
         */
        'max_live_pending' => (int) env('KIOSK_PAIRING_MAX_LIVE_PENDING', 20),

        /*
         * Cuantas horas se conservan las solicitudes ya consumidas o caducadas.
         *
         * NO ES RETENCION LEGAL: aqui no hay ni un dato personal —dos hashes, una
         * version de la PWA y unos instantes— asi que no hay nada que retener. Es
         * higiene: la tabla no crece sin limite y el espacio de codigos no se
         * agota. La purga es PEREZOSA y ocurre al crear una solicitud nueva, no en
         * el scheduler: un barrido programado que no corra dejaria de purgar en
         * silencio, y este barrido solo hace falta cuando alguien empareja.
         *
         * Veinticuatro horas son el margen que hace falta para diagnosticar un
         * emparejamiento que salio mal el dia anterior.
         */
        'purge_after_hours' => (int) env('KIOSK_PAIRING_PURGE_AFTER_HOURS', 24),

        /*
         * EL SUELO DE TIEMPO DEL RECHAZO NO ESTA AQUI: es
         * `security.rejection_floor_ms`, compartido con la resolucion de
         * credenciales del fichaje. Los dos caminos tienen la misma obligacion
         * (RS-03) y dos numeros para el mismo control acaban con uno de ellos a
         * cero por descuido.
         */
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

    /*
     * Los dos plazos con los que `php artisan kiosk:health` juzga un latido
     * (RF-PA-07, doc 02 Anexo C).
     *
     * SON DOS, Y NINGUNO ES NUEVO. Los dos estaban ya escritos en documentacion
     * que el cliente tiene en la mano, y este bloque solo los pone donde el
     * codigo puede leerlos:
     *
     *   - `fresh_within_seconds` = 120 s. El runbook `alta-nuevo-quiosco.md`
     *     §4.2 manda comprobar que «su ultimo contacto es de hace menos de dos
     *     minutos», y el §4.3 avisa de que «si pasa de dos o tres minutos, la
     *     tablet no esta hablando con el servidor». Con el latido cada 60 s
     *     (§6), dos minutos son DOS latidos perdidos: uno suelto puede ser un
     *     wifi que parpadea, y avisar por eso seria enseñar a ignorar el aviso.
     *
     *   - `silent_after_seconds` = 600 s. Es el umbral de la alerta «Quiosco sin
     *     latido > 10 min, Critica (operaciones)» del doc 01 §9.3, con su
     *     runbook `quiosco-no-responde.md`. EL MISMO NUMERO Y NO OTRO: un
     *     comando que dijera «aviso» de un quiosco por el que la observabilidad
     *     esta paginando a las 06:00 obligaria a decidir cual de los dos tiene
     *     razon, y eso se decide mal a esa hora.
     *
     * Configuracion y no constantes (regla dura 13, ADR-017): un hotel con la
     * wifi justa y otro con red cableada no tienen la misma paciencia razonable.
     * La raiz de composicion los ordena antes de construir el objeto de valor,
     * asi que un `.env` con los dos numeros cruzados da un diagnostico raro pero
     * nunca deja a nadie sin diagnostico.
     */
    'health' => [
        'fresh_within_seconds' => (int) env('KIOSK_HEALTH_FRESH_WITHIN_SECONDS', 120),
        'silent_after_seconds' => (int) env('KIOSK_HEALTH_SILENT_AFTER_SECONDS', 600),
    ],

];
