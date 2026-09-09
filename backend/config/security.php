<?php

declare(strict_types=1);

/*
 * Controles de seguridad que **atraviesan modulos** (doc 02 §7).
 *
 * QUE ENTRA AQUI Y QUE NO. Solo lo que gobierna un mismo control aplicado en mas
 * de un sitio. Lo que es de un modulo vive en el suyo: los ambitos de token y la
 * vida de una credencial en `identity.php`, los limitadores del quiosco en
 * `kiosk.php`, los umbrales legales en el perfil de cumplimiento (regla dura 14).
 *
 * POR QUE ESTE FICHERO EXISTE. Porque el suelo de tiempo constante de RS-03 lo
 * aplican dos modulos —el resolutor de credenciales del fichaje y la recogida del
 * emparejamiento— y **tenia dos claves**, una en cada uno. Dos numeros para el
 * mismo control acaban divergiendo, y el sintoma de que uno se quede a cero es
 * que un camino de rechazo deja de estar protegido sin que ninguna prueba del
 * otro lo note. Una sola clave, un solo comportamiento.
 */

return [

    /*
     * Duracion minima, en milisegundos, de **todo camino de rechazo** que RS-03
     * obliga a hacer indistinguible: la resolucion de una credencial QR y la
     * recogida de un emparejamiento.
     *
     * NO SUSTITUYE A IGUALAR EL TRABAJO, que es la mitad estructural del control
     * y la que hay que hacer primero: los caminos ejecutan las mismas consultas y
     * el mismo `hash_equals` aunque no haya fila. El suelo absorbe la varianza que
     * queda —cache de PostgreSQL, planificador del contenedor— y es lo que hace
     * que la prueba de tiempo constante signifique algo en vez de ser
     * intermitente.
     *
     * SE APLICA SOLO AL RECHAZO. Igualar tambien la aceptacion obligaria a un
     * suelo tan alto que se notaria en el cambio de turno, y no aporta nada: la
     * respuesta ya dice si el intento se acepto.
     *
     * 25 ms estan un orden de magnitud por encima de la diferencia que separa un
     * acierto de indice de un fallo, y muy por debajo de lo que una persona nota
     * delante de un quiosco.
     *
     * A CERO SE DESACTIVA, y solo tiene sentido para depurar en local: las dos
     * pruebas de RS-03 fallan si lo encuentran apagado.
     */
    'rejection_floor_ms' => (int) env('IDENTITY_CREDENTIAL_REJECTION_FLOOR_MS', 25),

    /*
     * Si esta instalacion admite un certificado TLS autofirmado
     * (`TLS_ALLOW_SELF_SIGNED`, tarea 5.9).
     *
     * QUIEN DECIDE DE VERDAD ES NGINX, no esto: la variable la lee su punto de
     * entrada, que genera un certificado autofirmado cuando no encuentra el del
     * hotel. Esta clave existe para que la aplicacion pueda **decirlo**: la sonda
     * `tls.certificate` de `product:doctor` avisa cuando encuentra un
     * certificado autofirmado en una instalacion que declara no aceptarlos, que
     * es la señal de que el certificado del hotel no llego a copiarse y nadie se
     * dio cuenta.
     *
     * `false` de serie, que es lo correcto en el servidor de un cliente.
     */
    'tls_allow_self_signed' => filter_var(env('TLS_ALLOW_SELF_SIGNED', false), FILTER_VALIDATE_BOOL),

];
