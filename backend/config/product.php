<?php

declare(strict_types=1);

/*
 * Modulo Product — lo que es del DESPLIEGUE, no de la instalacion.
 *
 * OJO A LA DISTINCION, QUE ES LA RAZON DE SER DEL MODULO. Marca, idiomas y
 * umbrales operativos NO estan aqui: viven en `installation_settings`, se editan
 * desde el panel sin reiniciar nada y cada cambio queda auditado (RF-PD-01,
 * ADR-017). Su catalogo, con sus valores de serie, es
 * `App\Modules\Product\Domain\ValueObject\SettingKey`.
 *
 * Lo que sI cabe en este fichero es lo que no tiene sentido editar sin
 * reiniciar y no se audita: hoy, cada cuanto se repite un aviso tecnico.
 */

return [

    /*
     * Cada cuantos segundos se repite el aviso de que la configuracion guardada
     * tiene una fila que no se puede aplicar.
     *
     * Quien lee la configuracion es el camino de fichaje: `RegisterScanHandler`
     * la pide en CADA escaneo, asi que un `warning` por lectura serian cincuenta
     * por segundo en un cambio de turno (RNF-P-06). Se agrupa por ventana, con la
     * misma palanca que ADR-037 aplica a las lecturas de datos personales:
     * agrupar por frecuencia sin quitar el aviso.
     *
     * Alineado con el TTL de la cache de configuracion (300 s), que es el otro
     * plazo en el que una corrupcion introducida a mano se hace visible. Una
     * anomalia NUEVA se anuncia de inmediato aunque la anterior siga dentro de su
     * ventana: la firma entra en la clave.
     *
     * `0` desactiva la agrupacion y deja un aviso por lectura. Solo tiene sentido
     * mientras se depura algo, nunca en produccion.
     */
    'settings_anomaly_window_seconds' => (int) env('PRODUCT_SETTINGS_ANOMALY_WINDOW_SECONDS', 300),

    /*
     * Peticiones por minuto y por origen de las DOS rutas publicas del asistente
     * de puesta en marcha (RF-PD-03): `GET /api/v1/setup/status` y
     * `POST /api/v1/setup/administrator`.
     *
     * ZONA PROPIA Y NO `throttle:auth`, porque aquella compone su clave por
     * cuenta con el `email` del cuerpo y aqui no hay ninguna cuenta a la que
     * contar: la que se va a crear todavia no existe. Con la zona de acceso,
     * todo el trafico del asistente compartiria el cubo de la cadena vacia —el
     * mismo fallo que la tarea 2.1 corrigio en `/auth/2fa/*`— y ademas gastaria
     * el cupo de acceso de la persona que esta a punto de entrar.
     *
     * 10 NO ES UNA MEDICION: la puesta en marcha la hace una persona, una vez, y
     * consulta el estado entre paso y paso. Deja margen de sobra para eso y
     * corta un bucle en el primer segundo. Se puede subir si una instalacion con
     * NAT delante deja al panel compartiendo IP con media oficina.
     */
    'setup_rate_limit_per_minute' => (int) env('PRODUCT_SETUP_RATE_LIMIT', 10),

    /*
     * Peticiones por minuto y por origen de las dos rutas publicas de la MARCA
     * (RF-PD-08): `GET /api/v1/branding` y `GET /api/v1/branding/logo`.
     *
     * ZONA PROPIA Y NO `throttle:setup`. Aquella tiene 10 r/m porque protege un
     * acto que ocurre una vez en la vida de la instalacion; esta la piden
     * NAVEGADORES AL ARRANCAR, y con veinte tablets, el panel de recepcion y los
     * moviles de la plantilla entrando al portal, diez por minuto se agotan solos.
     * Compartir cubo con el asistente ademas dejaria a una puesta en marcha sin
     * cupo por culpa del trafico normal.
     *
     * 120 NO ES UNA MEDICION: es margen de sobra para el arranque simultaneo de
     * toda la plantilla de un hotel detras de una sola IP —que es lo normal, con
     * NAT— y sigue cortando un bucle en el primer segundo. Lo que protege no es
     * un secreto: lo que revela esta respuesta es el nombre del hotel y su color,
     * que es lo mismo que revela la tarjeta que cada empleado lleva en el
     * bolsillo. Es un techo de ruido, no un control de acceso.
     */
    'branding_rate_limit_per_minute' => (int) env('PRODUCT_BRANDING_RATE_LIMIT', 120),

    /*
     * Tamaño maximo del paquete de diagnostico, en bytes (RF-PD-09, ADR-020).
     *
     * NO ES UN LIMITE DE MEMORIA: es un limite de CANAL. El paquete se envia por
     * el medio que tenga contratado el cliente —correo, portal de tickets— y ese
     * medio corta. Un paquete de 300 MB no llega a soporte, y el cliente lo
     * descubre cuando lleva dos dias esperando respuesta.
     *
     * 8 MiB deja sitio de sobra para lo tecnico —la parte anonimizada de una
     * instalacion real no llega a 200 KB— y acota lo unico que puede crecer sin
     * techo, que es `personal_data`. Al superarse, las secciones se sacrifican en
     * el orden fijo de `DiagnosticsBundle`, empezando por `personal_data`, y lo
     * omitido queda anotado con su tamaño: nunca se recorta en silencio.
     *
     * Se puede subir si un cliente tiene un canal que admite mas y necesita un
     * periodo largo de datos personales. Subirlo NO amplia lo que se recoge:
     * amplia lo que cabe.
     */
    'diagnostics_max_bytes' => (int) env('PRODUCT_DIAGNOSTICS_MAX_BYTES', 8 * 1024 * 1024),

    /*
     * Peticiones por minuto y por cuenta de `POST /api/v1/diagnostics/bundle`.
     *
     * ZONA PROPIA Y MAS ESTRECHA QUE `throttle:management` (120 r/m), y no es
     * exceso de celo: **generar el paquete recorre la instalacion entera**. Lee
     * la plantilla, cuenta el `audit_log`, abre un socket TLS contra el borde,
     * mide dos sistemas de ficheros y ejecuta las ocho familias de `doctor`. Con
     * el techo de gestion, un boton pulsado con impaciencia —o un panel con un
     * reintento mal puesto— pondria ciento veinte de esos recorridos por minuto
     * sobre la misma base de datos por la que pasa cada fichaje.
     *
     * 3 NO ES UNA MEDICION: generar el paquete es un acto deliberado que una
     * persona hace una vez, mira el fichero y envia. Tres deja margen para
     * equivocarse de opcion y repetir, y corta un bucle en el primer segundo.
     */
    'diagnostics_rate_limit_per_minute' => (int) env('PRODUCT_DIAGNOSTICS_RATE_LIMIT', 3),

    /*
     * Dias hacia atras que puede abarcar como maximo la seccion `personal_data`
     * (RL-19).
     *
     * 31, alineado con el maximo del contrato. El limite existe para que la
     * bandera de datos personales no se convierta en **una exportacion del
     * registro horario por la puerta de atras**: un mes cubre cualquier
     * incidencia de nomina —que es el caso de uso real— y cuatro años serian
     * copiar el registro legal entero a un fichero que sale del servidor.
     *
     * Subirlo es una decision con consecuencias legales, no un ajuste de
     * rendimiento: lo que se amplia es la cantidad de datos personales que puede
     * salir de la instalacion en un solo fichero.
     */
    'diagnostics_personal_data_max_period_days' => (int) env('PRODUCT_DIAGNOSTICS_PERSONAL_DATA_MAX_PERIOD_DAYS', 31),

    /*
     * Dias que un paquete de diagnostico se queda en el disco antes de que
     * `product:diagnostics` lo borre al generar el siguiente (RL-19).
     *
     * UN PAQUETE ES MATERIAL CADUCADO EN CUANTO SE ENVIA. El anonimizado ocupa
     * sitio sin aportar nada; el que se pidio con `--with-personal-data` es una
     * copia de la plantilla y de los fichajes de un periodo, en el disco del
     * cliente y sin ninguna fecha de caducidad. RL-19 autoriza a generarlo para
     * una incidencia concreta, no a conservarlo indefinidamente: sin este plazo,
     * el directorio se convertiria en un almacen paralelo de datos personales
     * que nadie mira y que ninguna retencion cubre.
     *
     * 7 dias dan margen de sobra para el ciclo real de una incidencia —generar,
     * inspeccionar, enviar, comentar— sin dejar nada criando polvo un mes.
     *
     * El borrado ocurre al ejecutar el comando y NO en una tarea programada: una
     * tarea que borrase ficheros del cliente por su cuenta seria una sorpresa.
     * Quien no vuelva a generar nunca conserva su ultimo paquete, y eso es
     * correcto: nadie ha pedido nada.
     */
    'diagnostics_retention_days' => (int) env('PRODUCT_DIAGNOSTICS_RETENTION_DAYS', 7),
    /*
     * Directorio donde `php artisan product:diagnostics` deja el fichero.
     *
     * Dentro de `storage/app` porque es el unico sitio escribible por la
     * aplicacion que existe con seguridad en toda instalacion, y **no** dentro
     * de `BACKUP_PATH`: ahi vive lo que hay que conservar, y un paquete de
     * diagnostico es material desechable que ademas puede llevar datos
     * personales. Mezclarlos haria que la retencion de copias conservara durante
     * meses ficheros que deberian borrarse en cuanto se envian.
     *
     * El escritor lo crea con permisos `0700` y el fichero con `0600`.
     */
    'diagnostics_storage_path' => env('PRODUCT_DIAGNOSTICS_PATH', storage_path('app/diagnostics')),

    /*
     * ACCESOS DE SOPORTE (RF-PD-11, RL-18, ADR-020, tarea 5.9).
     *
     * Los tres valores caben aqui y no en `installation_settings` por el criterio
     * de la cabecera de este fichero: **no se editan desde el panel**. Y no es un
     * descuido, es lo correcto. Si el maximo de horas de una concesion fuera una
     * clave editable, quien concede el acceso podria subirlo antes de concederlo
     * y el limite dejaria de ser un limite para convertirse en una sugerencia. Lo
     * cambia quien administra el servidor, en el `.env`, y consta en el
     * despliegue.
     */

    /*
     * Duracion de serie de una concesion, en horas, cuando no se pide otra.
     *
     * 24 NO ES UNA MEDICION: es el plazo en el que se resuelve una incidencia
     * normal con una zona horaria de por medio. Suficiente para que soporte mire
     * hoy y vuelva mañana; corto para que un acceso olvidado no dure el fin de
     * semana.
     */
    'support_grant_default_hours' => (int) env('PRODUCT_SUPPORT_GRANT_DEFAULT_HOURS', 24),

    /*
     * Tope de duracion de una concesion, en horas. Pedir mas es `422`.
     *
     * 72 son tres dias: cubre la incidencia que se abre un viernes y no se cierra
     * hasta el lunes. **El tope existe porque una concesion larga se olvida**, y
     * una concesion olvidada es lo que ADR-020 llama «una cuenta permanente con
     * otro nombre». Un cliente con una politica mas dura lo baja aqui sin tocar
     * el repositorio (regla dura 13); el contrato declara 72 porque un esquema
     * OpenAPI no puede leer configuracion, y una instalacion con el tope mas bajo
     * responde `422` antes de llegar a el — mas estricta que el contrato y nunca
     * mas laxa.
     */
    'support_grant_max_hours' => (int) env('PRODUCT_SUPPORT_GRANT_MAX_HOURS', 72),

    /*
     * Cada cuantos segundos se vuelve a auditar el USO de una misma concesion.
     *
     * `accessed_at` se actualiza en CADA peticion —es un `UPDATE` de una columna,
     * sin candado— y lo que se agrupa es el asiento de `audit_log`, que es
     * solo-apendice y encadenado bajo el candado global de ADR-010: el mismo por
     * el que pasa cada fichaje. Sin agrupar, una sesion de soporte de veinte
     * minutos serian cientos de escrituras en esa cadena, degradando el camino
     * del quiosco (regla dura 19).
     *
     * Misma palanca que ADR-037 aplica a las lecturas de datos personales y que
     * `settings_anomaly_window_seconds`: agrupar por frecuencia sin quitar el
     * aviso. 900 s son quince minutos, asi que una sesion de una hora deja cuatro
     * asientos — suficiente para reconstruir cuando estuvo dentro.
     *
     * `0` desactiva la agrupacion y deja un asiento por peticion. Solo tiene
     * sentido mientras se investiga un incidente de seguridad concreto.
     */
    'support_use_audit_window_seconds' => (int) env('PRODUCT_SUPPORT_USE_AUDIT_WINDOW_SECONDS', 900),

];
