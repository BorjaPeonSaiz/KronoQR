<?php

declare(strict_types=1);

/*
 * Acceso de gestion — RF-ID-01 (contrasena, politica de robustez y bloqueo por
 * intentos) y doc 02 §7.3 (vida de la sesion).
 *
 * TODO LO DE AQUI ES CONFIGURACION, NO CONSTANTES (regla dura 13, ADR-017). Un
 * cliente con una politica de seguridad mas dura sube el minimo de longitud o
 * baja el numero de intentos sin tocar el repositorio, y sin que exista una rama
 * por cliente.
 *
 * Los valores de serie son los del producto y estan documentados en
 * `.env.example`: son razonables para un hotel y ninguno viene de un cliente
 * concreto.
 *
 * El segundo factor obligatorio de RS-06 vive en el bloque `two_factor` de abajo
 * y lo implementa `pragmarx/google2fa` (doc 02 §3.1).
 */

return [

    'login' => [
        /*
         * Fallos consecutivos antes de bloquear la clave «cuenta + origen».
         *
         * No es el limite de peticiones del §7.1, que cuenta peticiones por
         * origen y se aplica en Nginx y en la ruta. Este cuenta FALLOS por
         * cuenta, que es lo que frena a quien prueba contrasenas contra un
         * correo conocido desde muchas IP: sin el, cinco peticiones por minuto
         * durante una noche siguen siendo miles de intentos.
         */
        'max_attempts' => (int) env('IDENTITY_LOGIN_MAX_ATTEMPTS', 5),

        /*
         * Duracion del bloqueo, en segundos. Quince minutos hacen inviable la
         * fuerza bruta y no dejan a nadie fuera media manana por teclear mal.
         */
        'lockout_seconds' => (int) env('IDENTITY_LOGIN_LOCKOUT_SECONDS', 900),
    ],

    'session' => [
        /*
         * Vida del token del panel, en horas (§7.3: «sesion corta» para
         * gestion). Se pasa token a token y no como caducidad global de Sanctum
         * porque el token del quiosco dura 90 dias (RF-ID-04): una sola
         * caducidad global obligaria a elegir cual de los dos se rompe.
         */
        'token_hours' => (int) env('IDENTITY_SESSION_TOKEN_HOURS', 12),
    ],

    /*
     * SEGUNDO FACTOR DE GESTION — RS-06, RF-ID-01, tarea 2.1.
     *
     * SOLO CUENTAS DE GESTION. Aqui no hay nada del empleado: su credencial es
     * una tarjeta fisica (ADR-014, regla dura 11) y su acceso al portal es codigo
     * y PIN (ADR-015, regla dura 12). Un TOTP para la plantilla contradiria las
     * dos decisiones.
     */
    'two_factor' => [

        /*
         * ROLES OBLIGADOS A LLEVAR SEGUNDO FACTOR.
         *
         * De serie, los tres literales de RS-06: `admin`, `rrhh` y `auditor`, que
         * son los que alcanzan datos de TODA la plantilla. El
         * `responsable_departamento` no entra porque su alcance esta acotado a su
         * departamento (RF-ID-03).
         *
         * CONTRADICCION DOCUMENTAL, RESUELTA POR CONFIGURACION. La tabla del doc
         * 02 §7.3 escribe «Sesion + 2FA» tambien en la fila del responsable.
         * Manda el doc 01 (orden de autoridad de `CLAUDE.md`), asi que el valor
         * de serie son tres roles; y como esto es configuracion y no una
         * constante (regla dura 13), un cliente con una politica mas dura anade
         * `responsable_departamento` sin tocar el repositorio y sin una rama
         * propia.
         *
         * QUIEN YA LO TIENE, LO USA, este o no en esta lista: quitar la
         * obligatoriedad no desactiva el segundo factor de quien lo activo.
         */
        'required_roles' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('IDENTITY_2FA_REQUIRED_ROLES', 'admin,rrhh,auditor')),
        ), static fn (string $role): bool => $role !== '')),

        /*
         * Vida de la SESION PENDIENTE, en minutos.
         *
         * MINUTOS Y NO HORAS, y no es simetria con la sesion de arriba: lo que
         * esta abierto entre `/auth/login` y `/auth/2fa/verify` es media
         * autenticacion. Con las doce horas del panel, una contrasena robada se
         * convertiria en un acceso pendiente de un unico codigo durante toda la
         * jornada. Diez minutos sobran para sacar el telefono y escribir seis
         * digitos.
         */
        'challenge_minutes' => (int) env('IDENTITY_2FA_CHALLENGE_MINUTES', 10),

        /*
         * Fallos de CODIGO consecutivos antes de bloquear.
         *
         * CONTADOR PROPIO, DISTINTO DEL DE LA CONTRASENA. Compartirlo tendria dos
         * efectos malos a la vez: gastar el cupo probando codigos dejaria a
         * alguien sin poder reintentar su contrasena, y alternar las dos puertas
         * duplicaria los intentos disponibles. Los umbrales de serie son los
         * mismos porque la amenaza se parece; el contador no.
         */
        'max_attempts' => (int) env('IDENTITY_2FA_MAX_ATTEMPTS', 5),

        'lockout_seconds' => (int) env('IDENTITY_2FA_LOCKOUT_SECONDS', 900),

        /*
         * Limite de PETICIONES de la zona `2fa` (§7.1: 5 r/m, el mismo que la de
         * acceso).
         *
         * ZONA PROPIA Y NO LA DE ACCESO, y no es reparto: aquella toma la cuenta
         * del `email` del cuerpo, y en `/auth/2fa/*` no hay ningun correo —el
         * sujeto viaja en el token pendiente—. Con la zona `auth`, la clave por
         * cuenta era la cadena vacia y los cinco intentos por minuto los compartia
         * la instalacion entera: cualquiera con un reto abierto dejaba a todos los
         * demas sin poder completar su acceso.
         *
         * ES OTRO CONTROL QUE EL DE ARRIBA. `max_attempts` cuenta FALLOS de codigo
         * por cuenta; esto cuenta PETICIONES por cuenta y por origen. Uno frena a
         * quien acierta la contrasena y prueba codigos; el otro, a quien inunda
         * los tres endpoints. Nginx pone el tercero en el borde.
         */
        'rate_limit_per_minute' => (int) env('IDENTITY_2FA_RATE_LIMIT', 5),

        /*
         * VENTANA DE TOLERANCIA, en franjas de 30 s a cada lado del instante
         * actual.
         *
         * Existe porque el reloj de un telefono se desvia. Sin ella, un movil
         * treinta segundos adelantado no puede entrar nunca y el sintoma —«a
         * veces me deja y a veces no»— es de los mas caros de diagnosticar. Con
         * el valor de serie, un codigo vale unos noventa segundos, que es el
         * compromiso estandar. Un cliente con relojes sincronizados por NTP puede
         * bajarlo a cero.
         */
        'window' => (int) env('IDENTITY_2FA_WINDOW', 1),

        /*
         * Longitud del secreto en caracteres base32. Treinta y dos son 160 bits,
         * el doble del minimo de la libreria: el coste es nulo y el secreto vive
         * años.
         */
        'secret_length' => (int) env('IDENTITY_2FA_SECRET_LENGTH', 32),

        /*
         * EMISOR que aparece en el autenticador, junto al correo de la cuenta.
         *
         * Es lo que distingue esta entrada de las demas en el telefono de quien
         * atiende varias instalaciones. Configurable porque la marca es
         * configuracion (ADR-017, regla dura 13): el valor de serie es el del
         * fabricante y un cliente con marca blanca (tarea 5.8) pone la suya.
         */
        'issuer' => (string) env('IDENTITY_2FA_ISSUER', 'KronoQR'),
    ],

    /*
     * Portal del empleado — RF-ID-05..08, RL-05.
     */
    'portal' => [
        /*
         * Vida de la sesion del portal, en horas.
         *
         * MAS CORTA QUE LA DEL PANEL, Y NO POR SIMETRIA. El §7.3 pide «sesion
         * corta» para el portal, y el motivo es concreto: esto se abre desde un
         * movil personal —con frecuencia prestado, compartido o sin bloqueo de
         * pantalla— y lo que hay detras son las horas de trabajo de una persona.
         * Doce horas, que son las del panel, dejarian la sesion viva desde el
         * turno de mañana hasta el de noche.
         *
         * DOS HORAS ES DECISION DE PRODUCTO, no una medicion ni un requisito
         * legal: sobra para mirar un mes de jornadas y descargarse el CSV, y no
         * llega para que la sesion siga abierta al dia siguiente. Como todo lo
         * de este fichero, es configuracion y no una constante (regla dura 13):
         * un cliente cuya plantilla consulta desde ordenadores de la propia
         * empresa puede subirla.
         *
         * NO BLOQUEA EL ACCESO AL REGISTRO cuando caduca: se vuelve a entrar con
         * codigo y PIN, que es lo unico que hace falta (ADR-015). La caducidad
         * de la LICENCIA, esa si, jamas afecta a este endpoint (ADR-019, regla
         * dura 15): el portal es registro legal.
         */
        'token_hours' => (int) env('IDENTITY_PORTAL_SESSION_HOURS', 2),

        /*
         * Limite de peticiones de la zona `portal` (§7.1: 10 r/m).
         *
         * Se aplica por IP **y** por codigo de empleado a la vez, no solo por
         * IP: en un hotel toda la plantilla que consulte desde la wifi del
         * centro sale por la misma linea, y un limite solo por IP dejaria a un
         * turno entero compartiendo diez intentos por minuto. Solo por codigo
         * seria peor: bastaria con rotar codigos.
         *
         * Nginx aplica ademas el suyo en el borde (§7.2). Los dos, porque el del
         * borde no distingue a quien se dirige la peticion y este no ve el
         * trafico que nunca llega a PHP.
         */
        'rate_limit_per_minute' => (int) env('IDENTITY_PORTAL_RATE_LIMIT', 10),
    ],

    /*
     * API de gestion — el panel de RRHH y de direccion.
     */
    'management' => [
        /*
         * Limite de peticiones de la zona `management`.
         *
         * MISMO TECHO QUE LA ZONA «RESTO» DE NGINX (§7.1: 120 r/m), no un numero
         * nuevo. Lo que aporta respecto al del borde es el EJE POR CUENTA: Nginx
         * no lee el token, asi que no puede distinguir dos sesiones que salen por
         * la misma linea del hotel.
         *
         * POR QUE HAY TECHO DE APLICACION EN ESTAS RUTAS. El listado de
         * plantilla, la ficha, el registro horario de una persona y las
         * correcciones son los cuatro sitios donde una denegacion por alcance
         * escribe `access.denied` en `audit_log` (RF-ID-03), y ese asiento pasa
         * por el candado global de ADR-010, el mismo por el que pasa cada
         * fichaje. Sin techo, un bucle sobre UUID ajenos mete escrituras
         * ilimitadas en el camino critico del cambio de turno.
         *
         * 120 R/M NO ES UNA MEDICION: un panel abierto consulta unidades de
         * peticiones por minuto, asi que deja margen de sobra para una persona
         * con prisa y corta un bucle automatizado en el primer segundo. Como todo
         * lo de este fichero, es configuracion y no una constante (regla dura
         * 13).
         */
        'rate_limit_per_minute' => (int) env('IDENTITY_MANAGEMENT_RATE_LIMIT', 120),
    ],

    'password' => [
        /*
         * Longitud minima de la contrasena de gestion (RF-ID-01). La politica
         * completa —mayusculas, minusculas, digitos y simbolos— se aplica al
         * FIJAR la contrasena, en `identity:create-user`.
         *
         * Sin comprobacion contra filtraciones publicas: esa regla consulta un
         * servicio externo por HTTP y el producto se instala en servidores sin
         * salida a internet (ADR-016).
         */
        'min_length' => (int) env('IDENTITY_PASSWORD_MIN_LENGTH', 12),
    ],

    /*
     * PIN del empleado — RF-ID-09, y con el RF-AT-11 (fichaje de respaldo) y
     * RL-05 (acceso al registro propio en el portal).
     *
     * LA LONGITUD NO ESTA AQUI, Y NO ES UN OLVIDO. Son seis digitos porque lo
     * dice el requisito y porque el contrato los fija (`IssuedPin.pin`,
     * `^[0-9]{6}$`): hacerla configurable significaria que una instalacion puede
     * emitir PIN que su propio cliente TypeScript rechaza.
     */
    'pin' => [

        /*
         * PIN que el generador nunca emite (regla dura 13: es una lista de
         * configuracion, no constantes en el codigo).
         *
         * POR QUE EXISTE. Un espacio de 10^6 con los tres primeros intentos
         * evidentes no es un espacio de 10^6. Un atacante con tres intentos
         * antes del bloqueo prueba `000000`, `123456` y `111111`, y con una
         * plantilla de cien personas acierta mas veces de lo que nadie querria
         * explicar.
         *
         * De serie: los diez repetidos y las doce secuencias de seis digitos
         * consecutivos, ascendentes y descendentes, con vuelta por el cero. Un
         * cliente con una politica mas dura anade los suyos —fechas tipicas, el
         * codigo postal del hotel— sin tocar el repositorio.
         */
        'forbidden' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('IDENTITY_PIN_FORBIDDEN', implode(',', [
                // Repetidos.
                '000000', '111111', '222222', '333333', '444444',
                '555555', '666666', '777777', '888888', '999999',
                // Ascendentes.
                '012345', '123456', '234567', '345678', '456789', '567890',
                // Descendentes.
                '543210', '654321', '765432', '876543', '987654', '098765',
            ]))),
        ), static fn (string $pin): bool => $pin !== '')),

        /*
         * BLOQUEO CRECIENTE POR INTENTOS FALLIDOS (RS-12, doc 02 §7.5).
         *
         * Separado del de la contrasena de gestion porque son dos poblaciones
         * distintas: quien teclea mal en un quiosco a las 06:00 con guantes
         * puestos no es quien prueba contrasenas contra el panel.
         *
         * TRES ESCALONES, NO UNO. El §7.5 exige «bloqueo temporal creciente tras
         * 3, 5 y 10 intentos fallidos», y estos son los numeros del Anexo B:
         *
         *     3 fallos  ->  5 min      max_attempts / lockout_seconds
         *     5 fallos  -> 15 min      lockout_tier2_*
         *    10 fallos  -> 60 min      lockout_tier3_*
         *
         * Escalado aproximadamente geometrico: cada escalon triplica al
         * anterior. Con eso, barrer un espacio de 10^6 es inviable y a quien se
         * equivoca una vez no se le castiga como a quien esta probando. Los
         * minutos son DECISION DE PRODUCTO —13 de agosto de 2026—, no una
         * medicion ni un requisito legal: son el equilibrio entre seguridad y no
         * dejar a nadie sin fichar delante del quiosco, y se mueven si la
         * operacion real de un cliente lo aconseja. Lo innegociable es que sean
         * configuracion y no constantes (regla dura 13).
         *
         * EL PRIMER ESCALON REUTILIZA LAS DOS CLAVES QUE YA EXISTIAN (tarea
         * 1.13) en vez de crear un `tier1_*` paralelo: dos nombres para el mismo
         * numero es la forma en que una instalacion acaba con el valor cambiado
         * en uno de los dos. Sus valores de serie BAJAN de 5/900 a 3/300 con la
         * tarea 1.12, que es lo que el Anexo B pedia desde el principio.
         *
         * POR EMPLEADO Y POR ORIGEN. El contador del quiosco y el del portal son
         * distintos (§7.5): sondear una puerta no puede cerrar la otra, porque
         * eso permitiria dejar a alguien sin fichar atacando su portal. Los
         * umbrales, en cambio, son los mismos para las dos.
         *
         * Lo consume el fichaje de respaldo (tarea 1.12) y el acceso al portal
         * (tarea 1.11). Restablecer el PIN limpia los dos contadores: quien pide
         * uno nuevo tiene que poder usarlo en el momento (RF-ID-09).
         */
        'max_attempts' => (int) env('IDENTITY_PIN_MAX_ATTEMPTS', 3),

        'lockout_seconds' => (int) env('IDENTITY_PIN_LOCKOUT_SECONDS', 300),

        'lockout_tier2_attempts' => (int) env('IDENTITY_PIN_LOCKOUT_TIER2_ATTEMPTS', 5),

        'lockout_tier2_seconds' => (int) env('IDENTITY_PIN_LOCKOUT_TIER2_SECONDS', 900),

        'lockout_tier3_attempts' => (int) env('IDENTITY_PIN_LOCKOUT_TIER3_ATTEMPTS', 10),

        'lockout_tier3_seconds' => (int) env('IDENTITY_PIN_LOCKOUT_TIER3_SECONDS', 3600),

        /*
         * Horas sin fallos tras las que el contador vuelve a cero.
         *
         * VENTANA DESLIZANTE: la cuenta arranca en el ULTIMO fallo, no en el
         * primero. Si arrancara en el primero, quien fallara una vez cada
         * veintitres horas no acumularia nunca y el escalon alto seria
         * inalcanzable para justo el patron que existe para frenar.
         */
        'lockout_reset_hours' => (int) env('IDENTITY_PIN_LOCKOUT_RESET_HOURS', 24),

        /*
         * SOBRE CERRADO DEL PIN DEL QUIOSCO (RF-AT-11, RL-12, regla dura 19).
         *
         * POR QUE EXISTE. El quiosco no puede esperar a tener red para aceptar
         * un fichaje. Con la tarjeta es facil —el padron cacheado resuelve el QR
         * sin servidor—, pero un PIN no se puede verificar sin `pin_hash`, que
         * no sale de aqui. La unica salida que no obliga a elegir entre
         * «bloquear al empleado» y «guardar el PIN en claro en IndexedDB» es que
         * la tablet SELLE el PIN con esta clave publica al teclearlo: lo que
         * queda en la cola es un criptograma que solo este servidor puede abrir.
         *
         * UNA SOLA CLAVE EN LA CONFIGURACION, LA PRIVADA. La publica se deriva
         * de ella y se sirve en `GET /kiosk/roster`. Guardar las dos permitiria
         * emparejarlas mal, y el sintoma de eso es que todos los fichajes por
         * PIN se rechazan mientras nada mas parece roto.
         *
         * SE GENERA EN EL SERVIDOR DEL CLIENTE Y NO SE TRANSMITE (§7.7, regla
         * dura 13). Quien tenga esta clave puede leer los PIN que viajen
         * sellados. Nunca en el repositorio, nunca compartida entre
         * instalaciones. Para generarla:
         *
         *   php artisan tinker --execute="echo base64_encode(sodium_crypto_box_secretkey(sodium_crypto_box_keypair()));"
         *
         * VACIA ES UN CASO LEGITIMO, NO UNA AVERIA: significa que esta
         * instalacion no ofrece fichaje por PIN. El quiosco oculta el teclado en
         * vez de ofrecer una puerta que rechaza siempre (ADR-017).
         */
        'sealing' => [
            // Cadena siempre, nunca nulo: `Config::string()` es estricto con el
            // tipo, y «no configurado» tiene que poder leerse sin excepcion
            // porque es un estado normal del producto.
            'secret_key' => (string) env('IDENTITY_PIN_SEALING_SECRET_KEY', ''),
        ],
    ],

    /*
     * Credencial QR (doc 02 §5, ADR-005, RF-QR-01..03).
     *
     * LAS CLAVES SON CONFIGURACION Y SE GENERAN EN EL SERVIDOR DEL CLIENTE
     * (§7.7, regla dura 13). Nunca en el repositorio, nunca compartidas entre
     * instalaciones: quien tenga la clave puede fabricar la tarjeta de
     * cualquiera. El instalador las genera y no las transmite.
     */
    'credentials' => [

        /*
         * Las DOS claves del solape de §5.3. `current` firma lo que se emite
         * hoy; `previous` sigue verificando las tarjetas ya impresas mientras
         * dura la reimpresion progresiva (RF-QR-07, tarea 2.12).
         *
         * `id` son los dos caracteres que viajan en el payload —`FH1.a3.…`— y
         * `secret` son 32 bytes en base64. Sin `key_id` habria que reimprimir a
         * toda la plantilla en un solo dia.
         *
         * `previous` puede no existir: fuera de una rotacion lo normal es tener
         * una sola clave activa.
         */
        'signing_keys' => [
            'current' => [
                'id' => env('QR_SIGNING_KEY_CURRENT_ID'),
                'secret' => env('QR_SIGNING_KEY_CURRENT'),
            ],
            'previous' => [
                'id' => env('QR_SIGNING_KEY_PREVIOUS_ID'),
                'secret' => env('QR_SIGNING_KEY_PREVIOUS'),
            ],
        ],

        /*
         * Suelo de tiempo, en milisegundos, que consume TODO rechazo de
         * credencial (RS-03, regla dura 17).
         *
         * No es paranoia: los cuatro rechazos —prefijo, clave, firma, credencial
         * desconocida o revocada— recorren pasos distintos y, sin suelo, la
         * diferencia de microsegundos entre «no hay fila» y «hay fila revocada»
         * es medible desde fuera y convierte el quiosco en un oraculo de que
         * tarjetas existen. El verificador hace ademas el mismo trabajo en los
         * cuatro caminos; el suelo absorbe la varianza que queda —cache de
         * PostgreSQL, planificador— y es lo que hace que la prueba de tiempo
         * constante signifique algo en vez de ser intermitente.
         *
         * Se aplica SOLO al rechazo. Igualar tambien la aceptacion no aporta
         * nada: la respuesta ya dice si el escaneo se acepto.
         */
        'rejection_floor_ms' => (int) env('IDENTITY_CREDENTIAL_REJECTION_FLOOR_MS', 25),

        /*
         * La tarjeta impresa (RF-QR-04, RF-QR-05, tarea 1.10).
         */
        'card' => [

            /*
             * Nivel de correccion de errores del QR. `Q` es el valor del Anexo B
             * y el que exige RF-QR-05: recupera hasta el 25 % de los modulos
             * dañados.
             *
             * El doc 02 §5.1 justifica el margen: «es lo que permite que una
             * tarjeta sobreviva una temporada de uso diario en una cocina, con
             * roces, grasa y dobleces». `L` o `M` producen un QR mas pequeño y
             * una tarjeta que deja de leerse en marzo; `H` obliga a un modulo mas
             * grande sin ganancia practica sobre `Q` para 47 caracteres.
             *
             * Es configuracion y no una constante (regla dura 13) porque un
             * cliente con tarjetas plastificadas y trabajo de oficina puede
             * bajarlo, y uno con cocina industrial subirlo.
             */
            'error_correction' => env('QR_ERROR_CORRECTION', 'Q'),

            /*
             * Lado del QR impreso, en milimetros.
             *
             * 26 mm sobre una tarjeta de 85,6 x 54 mm es el «tamaño minimo
             * garantizado» de RF-QR-05: con un payload de 47 caracteres y nivel
             * Q, el simbolo tiene 33 x 33 modulos, asi que cada modulo mide unos
             * 0,79 mm. Por debajo de 0,5 mm por modulo, la camara de una tablet
             * de gama media a 20 cm empieza a fallar; por encima de 30 mm el QR
             * se come el espacio del nombre.
             */
            'qr_size_mm' => (float) env('QR_SIZE_MM', 26.0),
        ],
    ],

    /*
     * Tokens de dispositivo del quiosco (RF-ID-04, RS-04, doc 02 §7.3).
     */
    'devices' => [

        /*
         * Vida del token, en dias. §7.3: 90. No es una sesion de panel: la
         * tablet esta colgada de una pared y nadie va a volver a escribir una
         * contrasena en ella cada manana.
         */
        'token_days' => (int) env('IDENTITY_DEVICE_TOKEN_DAYS', 90),

        /*
         * Fraccion de vida consumida a partir de la cual el token se rota
         * automaticamente (§7.3: «al 80 % de vida»). Con 90 dias son 72.
         *
         * Que sea una fraccion y no un numero de dias es lo que hace que
         * cambiar `token_days` no obligue a recalcular esto a mano.
         */
        'token_rotation_threshold' => (float) env('IDENTITY_DEVICE_TOKEN_ROTATION_THRESHOLD', 0.8),
    ],

];
