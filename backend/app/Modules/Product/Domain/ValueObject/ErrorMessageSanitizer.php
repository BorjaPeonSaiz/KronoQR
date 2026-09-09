<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Quita del mensaje de un error **todo lo que pueda identificar a una persona o
 * abrir una puerta** (RF-PD-15, RL-19, regla dura 21, decision 5 de la ficha
 * 5.12).
 *
 * ## Es de SERVIDOR, y esa es la decision
 *
 * El quiosco, el panel y el portal ya sanean antes de enviar. **No se confia en
 * ello.** Un cliente es codigo que corre en un dispositivo del cliente: puede
 * estar en una version antigua, puede tener un fallo, y en el peor caso puede
 * no ser nuestro cliente. Este historico viaja dentro del paquete de
 * diagnostico hacia el fabricante (ADR-020), asi que la ultima linea de defensa
 * tiene que estar de este lado. La prueba que lo fija envia PII desde un cliente
 * y mira la tabla.
 *
 * ## La regla que de verdad hace el trabajo: lo entrecomillado desaparece
 *
 * Las cinco primeras reglas atrapan formas conocidas —un correo, un DNI, un
 * telefono, una hora, un secreto—. **Ninguna atrapa un nombre de persona**, y un
 * nombre de persona no tiene forma reconocible: «Ana Ruiz» es indistinguible de
 * «Cocina Central» para cualquier expresion regular.
 *
 * Lo que si es reconocible es **donde** aparece. Las excepciones interpolan
 * valores entrecomillados —`Employee 'Ana Ruiz' not found`, `SQLSTATE[23505]
 * Key (email)=(ana@hotel.es) already exists`— porque es la convencion de PHP, de
 * PostgreSQL y de casi todo lo demas. Asi que **todo texto entre comillas
 * simples, dobles o angulares se sustituye por `'…'`**, sin mirar lo que lleva
 * dentro.
 *
 * Es deliberadamente destructivo. Se pierde informacion util —el nombre de la
 * columna que choco, por ejemplo— y a cambio se gana que un nombre no pueda
 * salir de la instalacion por esta via. Con el historico de errores viajando
 * hacia el fabricante, es el cambio correcto: lo que queda («SQLSTATE[23505]
 * duplicate key value violates unique constraint '…'») sigue diciendo que paso,
 * y el `trace_id` lleva a quien si tiene acceso hasta el log tecnico completo.
 *
 * ## El orden importa y es este
 *
 * 1. **Secretos** (`Bearer …`, `FH1.…`, `clave=valor`). Van primero porque un
 *    token lleva dentro cadenas que parecen otras cosas, y partirlo por la mitad
 *    con otra regla dejaria trozos reconocibles.
 * 2. **Correos**, antes que los numeros: `ana.ruiz+turno@hotel.es` tiene cifras
 *    que otra regla podria comerse dejando el dominio a la vista.
 * 3. **Documentos** (DNI y NIE) y **telefonos**, en ese orden: un DNI es ocho
 *    cifras y una letra, y el telefono acabaria mordiendole las cifras.
 * 4. **Fechas y horas**, porque una hora de fichaje es un dato de jornada de una
 *    persona concreta y esta tabla no guarda jornadas.
 * 5. **Lo entrecomillado**, al final: es la regla mas destructiva y se aplica
 *    sobre lo que las demas ya han marcado, de modo que un `[email]` fuera de
 *    comillas sigue siendo legible.
 *
 * ## Dominio puro
 *
 * Sin framework y sin estado: entra un texto, sale otro. Se puede probar entera
 * en una prueba unitaria sin base de datos, que es donde vive la lista de PII
 * que no puede pasar.
 */
final readonly class ErrorMessageSanitizer
{
    /**
     * Techo del mensaje, el mismo que el `maxLength` del contrato y el de la
     * columna. Un volcado de PostgreSQL con una fila entera dentro deja de ser
     * un mensaje mucho antes de los mil caracteres.
     */
    public const int MAX_LENGTH = 1000;

    /** Techo de un valor de `context`. Ver {@see ErrorContextAllowlist}. */
    public const int MAX_CONTEXT_LENGTH = 200;

    /**
     * Nombres que, a la izquierda de un `=` o de un `:`, marcan lo de la derecha
     * como secreto.
     *
     * Lista de **nombres**, no de formas: un valor de token no se distingue de
     * un identificador cualquiera mirandolo, pero `token=` si se distingue de
     * `route=`. Incluye las dos grafias del castellano porque los mensajes de
     * este producto estan en castellano.
     */
    private const string SECRET_NAMES = 'password|passwd|pwd|secret|token|api[_-]?key|apikey|'
        .'authorization|auth|bearer|pin|hash|signature|sig|credential|'
        .'clave|contrasena|contrase\x{00F1}a|secreto|firma';

    /**
     * El mensaje, listo para guardarse.
     *
     * Devuelve siempre algo: un mensaje vacio se convierte en un texto que dice
     * que estaba vacio, porque una fila con `message: ''` en el panel parece un
     * fallo del panel.
     */
    public static function sanitize(string $message): string
    {
        $clean = self::collapse($message);

        $clean = self::sql($clean);
        $clean = self::secrets($clean);
        $clean = self::emails($clean);
        $clean = self::documents($clean);
        $clean = self::phones($clean);
        $clean = self::instants($clean);
        $clean = self::quoted($clean);

        $clean = trim($clean);

        if ($clean === '') {
            return '(sin mensaje)';
        }

        return self::truncate($clean, self::MAX_LENGTH);
    }

    /**
     * Lo mismo para un valor de `context`, con su techo mas corto.
     *
     * Mismo saneado y no uno mas laxo: el contexto viaja al mismo sitio y lo
     * escribe el mismo cliente en el que no se confia.
     */
    public static function sanitizeContextValue(string $value): string
    {
        $clean = trim(self::sanitize($value));

        return self::truncate($clean, self::MAX_CONTEXT_LENGTH);
    }

    /**
     * Corta por caracteres y no por bytes.
     *
     * `substr()` sobre UTF-8 parte una tilde por la mitad y deja un byte
     * invalido que revienta al serializar el JSON del paquete de diagnostico —el
     * peor sitio para descubrirlo—.
     */
    private static function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // El indicador es un caracter, asi que el resultado cabe justo en el
        // techo: la columna no admite ni uno mas.
        return mb_substr($text, 0, $limit - 1).'…';
    }

    /**
     * Saltos de linea y tabuladores a un solo espacio.
     *
     * Una traza de PHP con veinte lineas dentro del mensaje hace ilegible la
     * tabla del panel y, sobre todo, mete rutas del servidor que la siguiente
     * regla no espera encontrar partidas.
     */
    private static function collapse(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * La **red de seguridad contra `QueryException`**, y va la primera de todas
     * (decision 5, revision de seguridad).
     *
     * ## Por que hace falta aunque el enganche ya lo evite
     *
     * `QueryException::getMessage()` de Laravel pega al final del mensaje la
     * consulta **con los parametros ya interpolados y sin comillas**:
     *
     *     …duplicate key value… (Connection: pgsql, SQL: insert into "employees"
     *     ("first_name","last_name") values (Maria, Gonzalez Perez, EMP-0042))
     *
     * Ahi hay un nombre, un apellido y un codigo de empleado en claro; en un
     * `update … set "pin_hash" = $2y$12$…` hay el hash del PIN de una persona.
     * Nada de eso puede estar en una tabla que viaja al fabricante (regla dura
     * 21, ADR-020), y el saneado general no lo atrapa porque los valores no van
     * entrecomillados.
     *
     * El enganche de captacion compone el mensaje con `getSql()` —los `?` sin
     * enlazar— en lugar de `getMessage()`, asi que en el camino normal esto no
     * llega a activarse. Existe porque **el camino normal no es el unico**: un
     * `report()` a mano, una excepcion envuelta por una libreria de terceros o un
     * cliente que reenvie lo que vio en su consola llegan por otras puertas, y
     * el saneado es la ultima linea antes de la columna.
     *
     * ## Dos reglas
     *
     * 1. **Todo lo que sigue a `, SQL: ` se corta.** No se sustituye por un
     *    marcador con la consulta dentro: la consulta ENTERA es el problema.
     *    Lo que queda —`SQLSTATE`, la restriccion que se violo— es lo que sirve
     *    para diagnosticar.
     * 2. **El `DETAIL: Key (columna)=(valor)` de PostgreSQL** pierde las dos
     *    partes. La columna sola seria informacion util, pero el motor las
     *    escribe pegadas y separar una expresion con parentesis anidados a base
     *    de expresiones regulares es como se dejan pasar los casos raros. Queda
     *    `Key ('…')=('…')`, que sigue diciendo «choco una clave» sin decir cual
     *    ni de quien.
     * 3. **El `DETAIL: Failing row contains (…)`**, que es la segunda fuga y la
     *    peor: ante un `NOT NULL` o un `CHECK`, PostgreSQL vuelca **la fila
     *    entera** —`(1, null, Maria, Gonzalez Perez, EMP-0042, …)`—. Ahi esta la
     *    plantilla en claro. Se sustituye por `Failing row contains [redacted]`.
     *
     * ## Las tres son redes, no el mecanismo principal
     *
     * El enganche de captacion compone su mensaje con el SQL **sin valores** y
     * separado por ` | sql: `, precisamente para que el corte de la primera regla
     * no se lo lleve. Pero el enganche no es el unico productor: por
     * `POST /api/v1/client-errors` entra lo que un navegador haya recogido, y
     * manana entrara lo que escriba quien anada un `report()` a mano. El saneado
     * es la ultima linea antes de la columna, y por eso las tres reglas viven
     * aqui y no solo alli.
     */
    private static function sql(string $text): string
    {
        $text = (string) preg_replace('/,\s*SQL:\s.*/su', '', $text);

        /*
         * HASTA EL FINAL, no hasta el primer parentesis de cierre: un valor de
         * la fila puede llevar parentesis dentro —un apellido compuesto entre
         * ellos, un texto de motivo— y un corte no voraz dejaria el resto de la
         * fila a la vista. Lo que se pierde detras es la cola de la excepcion,
         * que no diagnostica nada que `SQLSTATE` no diga ya.
         */
        $text = (string) preg_replace(
            '/\bFailing row contains\b.*/su',
            'Failing row contains [redacted]',
            $text,
        );

        return (string) preg_replace(
            '/\bKey\s*\([^)]*\)\s*=\s*\([^)]*\)/u',
            "Key ('…')=('…')",
            $text,
        );
    }

    private static function secrets(string $text): string
    {
        // Un payload de credencial completo (regla dura 10). No es PII, pero es
        // material firmado y no tiene por que salir de la instalacion.
        $text = (string) preg_replace('/\bFH1\.[A-Za-z0-9._~+\/-]+=*/', '[secret]', $text);

        $text = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', '[secret]', $text);

        return (string) preg_replace(
            '/\b('.self::SECRET_NAMES.')(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/iu',
            '$1$2[secret]',
            $text,
        );
    }

    private static function emails(string $text): string
    {
        return (string) preg_replace(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            '[email]',
            $text,
        );
    }

    /**
     * DNI y NIE espanoles: ocho cifras y una letra, o `X`/`Y`/`Z`, siete cifras
     * y una letra.
     *
     * **No se comprueba la letra de control** a proposito: el objetivo no es
     * validar documentos sino que no salgan, y un documento mal tecleado sigue
     * identificando a alguien igual de bien.
     */
    private static function documents(string $text): string
    {
        return (string) preg_replace('/\b[XYZxyz]?\d{7,8}[ -]?[A-Za-z]\b/', '[id]', $text);
    }

    /**
     * Telefonos en el formato que se usa en Espana: nueve cifras, con o sin
     * prefijo internacional y con o sin separadores.
     *
     * El patron exige **exactamente** tres grupos de tres cifras para no
     * comerse un numero tecnico cualquiera: un `Content-Length: 123456789` es un
     * falso positivo asumible; un `status 500` no puede serlo.
     */
    private static function phones(string $text): string
    {
        return (string) preg_replace(
            '/(?<![\w.\-])(?:\+\d{1,3}[ .\-]?)?\d{3}[ .\-]?\d{3}[ .\-]?\d{3}(?![\w.\-])/',
            '[phone]',
            $text,
        );
    }

    /**
     * Fechas e instantes completos primero, horas sueltas despues.
     *
     * **Una hora en un mensaje de error es sospechosa de ser una hora de
     * fichaje**, y las horas de fichaje de una persona son datos de jornada:
     * viven en `shift_entries` con cuatro anos de retencion y control de acceso,
     * no en una tabla tecnica de 90 dias que sale hacia el fabricante.
     */
    private static function instants(string $text): string
    {
        $text = (string) preg_replace(
            '/\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+\-]\d{2}:?\d{2})?)?/',
            '[time]',
            $text,
        );

        $text = (string) preg_replace('/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', '[time]', $text);

        return (string) preg_replace('/\b\d{1,2}:\d{2}(:\d{2})?\b/', '[time]', $text);
    }

    /**
     * Todo lo entrecomillado, sin mirar dentro. Ver el docblock de la clase: es
     * la unica regla que atrapa un nombre de persona.
     */
    private static function quoted(string $text): string
    {
        $text = (string) preg_replace('/"[^"]*"/u', "'…'", $text);
        $text = (string) preg_replace('/\x{00AB}[^\x{00BB}]*\x{00BB}/u', "'…'", $text);

        return (string) preg_replace('/\'[^\']*\'/u', "'…'", $text);
    }
}
