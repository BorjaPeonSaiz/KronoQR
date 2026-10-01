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
 * 3. **IBAN, codigo de empleado, pasaporte, documentos** (DNI y NIE, tambien
 *    con puntos y espacios) y **telefonos**, en ese orden: un IBAN son veinte
 *    cifras largas y un DNI ocho cifras y una letra, y el telefono acabaria
 *    mordiendoles las cifras.
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
     * Lo que devuelve {@see sanitize()} cuando no queda nada: un mensaje vacio,
     * solo espacios o un texto que no se pudo sanear (falla cerrado, ver
     * {@see redact()}). {@see ErrorContextAllowlist} lo usa para no guardar una
     * clave de contexto que no dice nada.
     */
    public const string EMPTY_MESSAGE = '(sin mensaje)';

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
        $clean = trim(self::redact(self::collapse($message)));

        if ($clean === '') {
            return self::EMPTY_MESSAGE;
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
        // Sin `trim()`: lo que devuelve `sanitize()` ya llega recortado —se
        // recorta antes de truncar y el truncado termina en `…`—, y un segundo
        // recorte no podia cambiar nada.
        return self::truncate(self::sanitize($value), self::MAX_CONTEXT_LENGTH);
    }

    /**
     * Las reglas y nada mas: sin colapsar espacios, sin techo y sin texto de
     * relleno (L1, regla dura 21).
     *
     * Es lo que aplica el log tecnico a cada linea —mensaje, excepcion y
     * valores de contexto—. No puede truncar: una linea de log de 3000
     * caracteres es legitima y cortarla esconderia el diagnostico. Y no
     * colapsa: una traza con saltos de linea sigue siendo legible en `stderr`.
     *
     * **Falla cerrado.** Si una expresion no se puede evaluar —un texto con
     * bytes UTF-8 invalidos, el limite de retroceso de PCRE—, `preg_replace`
     * devuelve `null` y aqui se convierte en cadena vacia: se pierde el texto,
     * no se deja pasar sin sanear. Quien llama decide que escribir en su lugar.
     *
     * Es idempotente: sanear dos veces da lo mismo que una, que es lo que
     * permite que la pila de canales de log aplique el processor una vez por
     * canal sin estropear lo ya saneado.
     */
    public static function redact(string $text): string
    {
        $clean = self::sql($text);
        $clean = self::secrets($clean);
        $clean = self::emails($clean);
        $clean = self::ibans($clean);
        $clean = self::employeeCodes($clean);
        $clean = self::passports($clean);
        $clean = self::documents($clean);
        $clean = self::phones($clean);
        $clean = self::instants($clean);

        return self::quoted($clean);
    }

    /**
     * El UNICO sitio donde se evalua una expresion, y donde se decide el fallo
     * cerrado.
     *
     * `preg_replace()` devuelve `null` cuando no puede evaluar el patron —un
     * texto con bytes UTF-8 invalidos contra un patron `/u`, el limite de
     * retroceso de PCRE—. Ese `null` se convierte aqui en cadena vacia: se
     * pierde el texto entero en lugar de dejarlo pasar sin sanear, y las reglas
     * que vienen detras ya trabajan sobre la cadena vacia. Con la decision en un
     * solo sitio, una prueba la fija para todas las reglas a la vez.
     */
    private static function replace(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text) ?? '';
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
        return self::replace('/\s+/u', ' ', $text);
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
        $text = self::replace('/,\s*SQL:\s.*/su', '', $text);

        /*
         * HASTA EL FINAL, no hasta el primer parentesis de cierre: un valor de
         * la fila puede llevar parentesis dentro —un apellido compuesto entre
         * ellos, un texto de motivo— y un corte no voraz dejaria el resto de la
         * fila a la vista. Lo que se pierde detras es la cola de la excepcion,
         * que no diagnostica nada que `SQLSTATE` no diga ya.
         */
        $text = self::replace(
            '/\bFailing row contains\b.*/su',
            'Failing row contains [redacted]',
            $text,
        );

        /*
         * El `Key (…)=(…)`, ANIDADO incluido (F4c-2). La version anterior
         * cortaba en el primer `)`, y PostgreSQL anida parentesis en cuanto la
         * clave es una expresion o una exclusion:
         *
         *     Key (employee_id, tstzrange(started_at, ended_at, '[)'::text))
         *       =(4242, ["2026-03-14 07:02:00+00","2026-03-14 15:00:00+00"))
         *       conflicts with existing key (…)=(…).
         *
         * Con `[^)]*` el patron no casaba y salian el `employee_id` y las dos
         * horas del tramo. Contar parentesis no sirve: el rango semiabierto
         * `[a,b)` del valor no esta equilibrado. Asi que se corta desde `Key (`
         * hasta la frase con la que PostgreSQL cierra el DETAIL —`already
         * exists`, `conflicts with`, `is not present`, `is still referenced`—
         * o hasta el final del texto. La anticipacion `[^=]*\)\s*=\s*\(` exige
         * que de verdad sea un `(columnas)=(valores)`: un «missing key (x)» de
         * otro mensaje no se lleva el resto de la linea.
         */
        return self::replace(
            '/\b(key)\s*\((?=[^=]*\)\s*=\s*\().*?(?=\s+(?:already exists|conflicts with|is not present|is still referenced)\b|$)/isu',
            "\$1 ('…')=('…')",
            $text,
        );
    }

    private static function secrets(string $text): string
    {
        // Un payload de credencial completo (regla dura 10). No es PII, pero es
        // material firmado y no tiene por que salir de la instalacion.
        $text = self::replace('/\bFH1\.[A-Za-z0-9._~+\/-]+=*/', '[secret]', $text);

        $text = self::replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', '[secret]', $text);

        return self::replace(
            '/\b('.self::SECRET_NAMES.')(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/iu',
            '$1$2[secret]',
            $text,
        );
    }

    private static function emails(string $text): string
    {
        return self::replace(
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
        // Compacto: `12345678Z`, `12345678-Z`, `X1234567L`, `X-1234567-L`.
        $text = self::replace('/\b(?:[XYZ][ .-]?|[xyz])?\d{7,8}[ .\-]?[A-Za-z]\b/', '[id]', $text);

        // Con separadores de miles, como se teclea a mano (F4c-2):
        // `12.345.678-Z`, `12 345 678 Z`, `X 1.234.567 L`. Exige los DOS
        // separadores entre grupos de tres cifras, que es lo que lo distingue
        // de un numero tecnico.
        return self::replace(
            '/(?<![\w.\-])(?:[XYZ][ .-]?|[xyz])?\d{1,2}[ .]\d{3}[ .]\d{3}[ .\-]?[A-Za-z](?!\w)/',
            '[id]',
            $text,
        );
    }

    /**
     * IBAN: dos letras de pais, dos cifras de control y de 12 a 31
     * alfanumericos, compacto o en grupos de cuatro separados por un espacio
     * (F4c-2).
     *
     * Va antes que documentos y telefonos: un IBAN espanol son veintidos cifras
     * y esas reglas se comerian trozos dejando el resto a la vista.
     *
     * **Solo en mayusculas, a proposito.** En minusculas casaria con cualquier
     * huella hexadecimal que empiece por dos letras y dos cifras —un `sha256`,
     * un identificador de commit—, que si aparecen en mensajes tecnicos. Y sin
     * guiones entre grupos: con ellos casaria un UUID en mayusculas. Un IBAN en
     * minusculas o con guiones es el falso negativo que se acepta.
     */
    private static function ibans(string $text): string
    {
        return self::replace(
            '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){3,7}(?: ?[A-Z0-9]{1,3})?\b/',
            '[iban]',
            $text,
        );
    }

    /**
     * El codigo de empleado (PR12, F4c-2): un identificador directo y la mitad
     * publica de la credencial del portal (ADR-015).
     *
     * Su forma la fija `Workforce\Domain\ValueObject\EmployeeCode::generate()`:
     * una `E` y nueve caracteres de un alfabeto sin ambiguos; la semilla de
     * desarrollo usa `E` y nueve hexadecimales, y el contrato documenta ejemplos
     * con ocho. Se atrapan dos cosas:
     *
     * 1. **La forma canonica**: `E` mas ocho o nueve mayusculas y cifras con al
     *    menos una cifra, o nueve letras **todas del alfabeto sin ambiguos**
     *    (un 6,8 % de los codigos generados no lleva cifras). Eso deja fuera
     *    palabras en mayusculas como `EXCEPTIONS` o `EVERYTHING`, que llevan
     *    `I`, `O` o `L`.
     * 2. **Cualquier valor detras de su nombre** —`employee_code=739104`,
     *    `codigo de empleado: AB12`—, porque `EmployeeCode::fromString()`
     *    acepta codigos heredados de cualquier forma alfanumerica, y esos solo
     *    se reconocen por la etiqueta.
     *
     * Lo que NO se atrapa: un codigo heredado sin etiqueta y sin la forma
     * canonica. Es indistinguible de un numero cualquiera; el `Key (…)=(…)` y
     * el corte del SQL cubren los dos sitios por donde de verdad aparece.
     */
    private static function employeeCodes(string $text): string
    {
        $text = self::replace(
            '/\b(employee[_ \-]?code|c(?:o|\x{00F3})digo(?:[_ ]de)?[_ ]empleado)(\s*[=:#]\s*|\s+(?=\S*\d))(\S+)/iu',
            '$1$2[code]',
            $text,
        );

        return self::replace(
            '/\bE(?=[A-Z0-9]{8,9}\b)(?:(?=[A-Z0-9]*\d)[A-Z0-9]{8,9}|[ABCDEFGHJKMNPQRSTUVWXYZ]{9})\b/',
            '[code]',
            $text,
        );
    }

    /**
     * Pasaportes (F4c-2): el espanol son tres letras y seis cifras, y
     * cualquiera, sea de donde sea, detras de la palabra.
     *
     * La forma sola es estrecha a proposito —mayusculas exactas y limites de
     * palabra— para no comerse un identificador tecnico; lo que no tenga esa
     * forma solo se reconoce por la etiqueta.
     */
    private static function passports(string $text): string
    {
        $text = self::replace(
            '/\b(passport|pasaporte)((?:\s*(?:no\.?|n\x{00BA}|n\x{00B0}|number|n(?:u|\x{00FA})mero))?(?:\s*[=:#]\s*|\s+(?=\S*\d)))(\S+)/iu',
            '$1$2[id]',
            $text,
        );

        return self::replace('/\b[A-Z]{3}\d{6}\b/', '[id]', $text);
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
        return self::replace(
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
        $text = self::replace(
            '/\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+\-]\d{2}:?\d{2})?)?/',
            '[time]',
            $text,
        );

        $text = self::replace('/\b\d{4}\/\d{1,2}\/\d{1,2}\b/', '[time]', $text);
        $text = self::replace('/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', '[time]', $text);

        // `dd-mm-aaaa` y `dd.mm.aaaa` (F4c-2), con el MISMO separador las dos
        // veces y dia, mes y siglo plausibles: sin eso, `13.0.1234` o un
        // `1-2-3000` cualquiera pasarian por fecha.
        $text = self::replace(
            '/\b(?:0?[1-9]|[12]\d|3[01])([\-.])(?:0?[1-9]|1[0-2])\1(?:19|20)\d{2}\b/',
            '[time]',
            $text,
        );

        return self::replace('/\b\d{1,2}:\d{2}(:\d{2})?\b/', '[time]', $text);
    }

    /**
     * Todo lo entrecomillado, sin mirar dentro. Ver el docblock de la clase: es
     * la unica regla que atrapa un nombre de persona.
     */
    private static function quoted(string $text): string
    {
        $text = self::replace('/"[^"]*"/u', "'…'", $text);
        $text = self::replace('/\x{00AB}[^\x{00BB}]*\x{00BB}/u', "'…'", $text);

        return self::replace('/\'[^\']*\'/u', "'…'", $text);
    }
}
