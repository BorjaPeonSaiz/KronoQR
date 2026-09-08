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

    /*
     * EXPORTACION INTEGRA DE LOS DATOS DEL CLIENTE (RF-PD-14, RL-20, tarea 5.10).
     *
     * Los tres caben aqui y no en `installation_settings` por el criterio de la
     * cabecera de este fichero: **no se editan desde el panel**. Son parametros
     * del servidor —donde vive el fichero, cuanto dura y cuantas veces por minuto
     * se puede pulsar el boton—, y quien los cambia es quien administra la
     * maquina, no quien usa el producto.
     */

    /*
     * Directorio donde se escribe el ZIP de la exportacion integra.
     *
     * Dentro de `storage/app` por lo mismo que el paquete de diagnostico: es el
     * unico sitio escribible por la aplicacion que existe con seguridad en toda
     * instalacion. El escritor crea el directorio con `0700` y el fichero con
     * `0600`.
     *
     * **NO SE PONE DENTRO DE `BACKUP_PATH`**, y aqui el motivo es aun mas fuerte
     * que en el diagnostico: una exportacion integra es una copia completa de la
     * plantilla y de cuatro años de fichajes que **caduca a los siete dias a
     * proposito**. Metida en el directorio de copias, la retencion de copias la
     * conservaria durante meses — justo lo contrario de lo que se quiere—, y
     * ademas se cifraria con la clave de copias, cuando el punto entero de esta
     * exportacion es que el cliente pueda abrirla sin depender de nada.
     *
     * Si el cliente quiere quedarse el fichero mas tiempo, lo saca del servidor
     * con `docker compose cp`, que es exactamente lo que se espera que haga.
     */
    'data_export_path' => env('PRODUCT_DATA_EXPORT_PATH', storage_path('app/exports')),

    /*
     * Dias que vive el ZIP antes de que la purga horaria lo borre.
     *
     * 7 NO ES UNA MEDICION: es el plazo en el que alguien que pide una
     * exportacion se la lleva. Quien la pide un viernes por la tarde la tiene el
     * lunes; quien no se la lleva en una semana es que ya no la necesitaba.
     *
     * **El plazo existe porque el fichero es lo mas peligroso que hay en el
     * disco de la instalacion**: la plantilla entera, sus fichajes, las cuentas
     * de gestion. Sin caducidad, cada exportacion pedida se quedaria ahi para
     * siempre y bastaria un acceso al servidor para llevarse todas las copias de
     * golpe. Un cliente con una politica mas dura lo baja a 1 sin tocar el
     * repositorio (regla dura 13).
     *
     * **La fila NUNCA se borra** (regla dura 5): purgar borra el fichero y marca
     * la fila como `purged`, que sigue apareciendo en la lista con sus fechas y
     * sus recuentos.
     */
    'data_export_retention_days' => (int) env('PRODUCT_DATA_EXPORT_RETENTION_DAYS', 7),

    /*
     * Segundos tras los cuales una exportacion sin terminar se declara ATASCADA
     * y pasa a `failed` con motivo `stale`.
     *
     * **Existe porque una fila atascada bloquea RL-20 entero.** Solo puede haber
     * una exportacion `pending|running` a la vez —lo impone un indice unico—, asi
     * que si el trabajador de cola muere, o alguien para los contenedores a mitad
     * (el paso 1 de cualquier actualizacion), la instalacion se queda sin poder
     * exportar: `409` eterno en el panel y salida `2` en la consola. Sin este
     * umbral, salir de ahi exigiria entrar por `psql`.
     *
     * 3600 NO ES UNA MEDICION: es exactamente el `timeout` del trabajo de cola
     * (`GenerateDataExportJob::TIMEOUT_SECONDS`), y las dos cifras estan atadas
     * por una prueba. El umbral **no puede ser menor** que el tiempo que la
     * generacion puede tardar legitimamente, o una exportacion grande se
     * declararia muerta mientras sigue escribiendo, y la siguiente peticion
     * empezaria una segunda copia completa sobre la misma base de datos.
     *
     * Se barre en dos sitios: al pedir una nueva —para que quien pulsa el boton
     * no espere a la hora en punto— y en la purga horaria.
     */
    'data_export_stale_after_seconds' => (int) env('PRODUCT_DATA_EXPORT_STALE_AFTER', 3600),

    /*
     * Peticiones por minuto a `/api/v1/data-export` **por cuenta**.
     *
     * 30 y no 3 como el diagnostico, y la diferencia no es un descuido: el
     * limitador cubre las TRES rutas, y **el panel sondea la lista cada cinco
     * segundos mientras dura la generacion** (doce peticiones por minuto). Con el
     * techo del diagnostico, la pantalla se bloquearia sola a los quince
     * segundos de pulsar el boton.
     *
     * El cubo **por IP** es cuatro veces este ({@see self::…} no aplica: lo
     * compone `ProductServiceProvider`), y ese factor existe por un caso real:
     * en un hotel, recepcion, direccion y el despacho de RRHH salen por la misma
     * IP publica. Con el mismo techo para los dos ejes, tres administradores
     * mirando la pantalla a la vez agotarian el cubo compartido y se cortarian
     * entre si — un `429` que el cliente leeria como «el producto esta roto».
     *
     * Lo que de verdad protege a la base de datos no es ninguno de los dos
     * numeros: es el indice unico parcial que impide dos exportaciones en curso a
     * la vez. Estos limites estan para que un cliente HTTP mal escrito no
     * convierta el sondeo en un bucle cerrado.
     */
    'data_export_rate_limit_per_minute' => (int) env('PRODUCT_DATA_EXPORT_RATE_LIMIT', 30),

    /*
     * TELEMETRIA OPCIONAL (RF-PD-12, ADR-020, ADR-023, tarea 5.10)
     * ------------------------------------------------------------------
     *
     * VIENE APAGADA Y NO HAY NINGUN CAMINO POR EL QUE SE ENCIENDA SOLA. Hacen
     * falta TRES cosas a la vez: esta variable en `true`, un destino en
     * `telemetry_endpoint` y `telemetry` entre las funcionalidades de la
     * licencia. Sin las tres, `product:telemetry --send` no construye ni envia
     * nada -ni siquiera lee un contador- y lo dice.
     *
     * Tres y no una porque cada una responde a una pregunta distinta: la
     * primera es la voluntad del cliente, la segunda es su configuracion y la
     * tercera es lo contratado. Un producto que enviara con solo la tercera
     * estaria decidiendo por el cliente.
     */
    'telemetry_enabled' => (bool) env('TELEMETRY_ENABLED', false),

    /*
     * A donde se envia. Vacio de serie: el producto no trae ningun destino
     * escrito, ni siquiera uno del fabricante.
     *
     * Es deliberado y es la mitad de la garantia. Con un destino de serie,
     * activar la variable enviaria a un sitio que el cliente no eligio; con el
     * vacio, activar la variable no hace nada hasta que alguien escribe a donde.
     * `docs/cliente/configuracion.md` seccion «3 quinquies. Telemetria» lleva la
     * tabla campo a campo de lo que sale.
     *
     * **Tiene que ser `https`**, y un destino que no lo sea se trata como si no
     * hubiera ninguno: el documento no lleva datos personales, pero si el tramo
     * de plantilla, el estado de licencia y el veredicto de cada comprobacion de
     * `doctor`, y en claro eso es un mapa util para quien mire la red. De quien
     * este al otro lado responde el cliente (riesgo aceptado, doc 07 §6).
     */
    'telemetry_endpoint' => (string) env('TELEMETRY_ENDPOINT', ''),

    /*
     * Donde vive `installation_id` y el historial de los envios.
     *
     * Un fichero y no una tabla: borrarlo tiene que ser un `rm`, porque estrenar
     * identidad ante el fabricante es algo que el cliente debe poder hacer solo
     * (ADR-020). Directorio `0700`, fichero `0600`.
     */
    'telemetry_state_path' => (string) env('TELEMETRY_STATE_PATH', storage_path('app/telemetry/state.json')),

    /*
     * Segundos entre el intento y su UNICO reintento.
     *
     * Cubre el fallo que de verdad se da: la ventana en la que el enlace del
     * hotel se esta renegociando. Insistir mas convertiria una tarea de fondo en
     * algo que ocupa un proceso, y el dato de esta semana no vale tanto. `0`
     * reintenta en el acto y es lo que usan las pruebas: una suite que duerme
     * cinco segundos por caso es una suite que nadie ejecuta.
     */
    'telemetry_retry_delay_seconds' => (int) env('TELEMETRY_RETRY_DELAY_SECONDS', 5),

];
