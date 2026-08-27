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
 * Sin nada de 2FA: el segundo factor obligatorio llega en la tarea 2.1
 * (Anexo A del doc 01). `pragmarx/google2fa` esta instalado y **no se usa**.
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
         * Bloqueo por intentos fallidos del PIN (RS-12), separado del de la
         * contrasena de gestion porque son dos poblaciones distintas: quien
         * teclea mal en un quiosco a las 06:00 con guantes puestos no es quien
         * prueba contrasenas contra el panel.
         *
         * Lo consume el fichaje de respaldo (tarea 1.12) y el acceso al portal
         * (tarea 1.11). Restablecer el PIN limpia el contador: quien pide uno
         * nuevo tiene que poder usarlo en el momento (RF-ID-09).
         */
        'max_attempts' => (int) env('IDENTITY_PIN_MAX_ATTEMPTS', 5),

        'lockout_seconds' => (int) env('IDENTITY_PIN_LOCKOUT_SECONDS', 900),
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
