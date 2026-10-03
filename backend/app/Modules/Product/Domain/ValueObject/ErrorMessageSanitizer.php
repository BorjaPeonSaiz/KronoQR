<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Quita del texto de un error **todo lo que pueda identificar a una persona o
 * abrir una puerta** (RF-PD-15, RL-19, reglas duras 16 y 21, ADR-048).
 *
 * ## Es de SERVIDOR, y esa es la decision
 *
 * El quiosco, el panel y el portal ya sanean antes de enviar. **No se confia en
 * ello.** Un cliente es codigo que corre en un dispositivo del cliente: puede
 * estar en una version antigua, puede tener un fallo, y en el peor caso puede
 * no ser nuestro cliente. Este historico viaja dentro del paquete de
 * diagnostico hacia el fabricante (ADR-020), asi que la ultima linea de defensa
 * tiene que estar de este lado.
 *
 * ## Lista blanca por palabra, no lista negra de formas (ADR-048)
 *
 * Hasta la 2.2.0 esta clase solo tenia patrones —correos, DNI, telefonos, lo
 * entrecomillado— y un nombre sin comillas pasaba: «Ana Ruiz» no se distingue
 * de «Cocina Central» con ninguna expresion regular. Ahora el texto pasa por
 * seis pasos, y el que hace el trabajo de fondo es el quinto:
 *
 * 1. **Colapsar** los espacios (solo {@see sanitize()}) y **quitar** los
 *    caracteres de uso privado U+E000–U+F8FF, que se reservan para el paso 2.
 * 2. **Proteger** lo que tiene que llegar intacto: los UUID en cualquier caja,
 *    los hexadecimales en minusculas de exactamente 16, 32, 40 o 64 caracteres
 *    con al menos una letra Y una cifra (span, traza, commit, sha256; H1: un
 *    numero de tarjeta de 16 cifras NO es un hexadecimal) y los `SQLSTATE[…]`.
 *    Un `FH1.…` se convierte aqui mismo en `[secret]`.
 * 3. **Patrones** ({@see redact()}): SQL interpolado, secretos, correos, IBAN,
 *    codigos de empleado, pasaportes, documentos, NAF, fechas y horas,
 *    direcciones IP, telefonos y lo entrecomillado.
 * 4. y 5. **Cifras y vocabulario** ({@see ErrorTextAllowlist}): toda palabra que
 *    no este en {@see ErrorVocabulary} pasa a `…`, y toda cifra larga a `[n]`.
 * 6. **Restaurar** lo protegido; en {@see sanitize()}, ademas, techo y texto de
 *    relleno.
 *
 * ## Tres funciones publicas, tres usos
 *
 * - {@see redact()}: pasos 1 (sin colapsar), 2, 3 y 6. **Solo patrones.** Es lo
 *   que aplica el log tecnico a los valores de su contexto, que escribe el
 *   codigo del producto: con el vocabulario se estropearian rutas, clases y
 *   nombres de trabajo en un log que no sale de la instalacion (D3).
 * - {@see redactText()}: los seis pasos sin colapsar ni techo. Es lo que aplica
 *   el log tecnico a su mensaje y a los mensajes de cada excepcion.
 * - {@see sanitize()} y {@see sanitizeContextValue()}: los seis pasos con su
 *   techo. Es lo que se guarda en `error_events` y lo que vuelve a aplicar el
 *   colector del paquete al leer.
 *
 * Las cuatro son **idempotentes** —sanear lo saneado no cambia nada— y **fallan
 * cerrado**: si una expresion no se puede evaluar se pierde el texto, no se
 * deja pasar sin sanear.
 *
 * ## Dominio puro
 *
 * Sin framework y sin estado: entra un texto, sale otro.
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
     * solo espacios o un texto que no se pudo sanear (falla cerrado).
     * {@see ErrorContextAllowlist} lo usa para no guardar una clave de contexto
     * que no dice nada.
     */
    public const string EMPTY_MESSAGE = '(sin mensaje)';

    /** El marcador de un UUID en el paquete anonimizado (H2). */
    public const string UUID = '[uuid]';

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
     * Un UUID, en cualquier caja, sin delimitadores. La unica copia de esta
     * forma en el modulo: la usan tambien `ErrorMessageNormalizer` y el log
     * tecnico para reconocer las claves de correlacion.
     */
    public const string UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * El paso 2. Por orden de alternativa: un payload de credencial (que no se
     * conserva: se convierte en `[secret]`), un UUID, un hexadecimal de las
     * cuatro longitudes con letra y cifra, y un `SQLSTATE[…]`.
     *
     * El hexadecimal NO se protege si tiene forma de IBAN —dos letras y solo
     * cifras detras—: un IBAN belga compacto en minusculas (`be71096123456769`)
     * son dieciseis caracteres hexadecimales con letra y cifra.
     */
    private const string PROTECTED = '/(?<secret>(?<![\p{L}])FH1\.[A-Za-z0-9._~+\/-]+=*)'
        .'|(?<![0-9A-Za-z])(?:'.self::UUID_PATTERN
        .'|(?![a-z]{2}\d+(?![0-9A-Za-z]))(?=[0-9a-f]*[a-f])(?=[0-9a-f]*[0-9])(?:[0-9a-f]{64}|[0-9a-f]{40}|[0-9a-f]{32}|[0-9a-f]{16}))(?![0-9A-Za-z])'
        .'|SQLSTATE\[[0-9A-Z]{5}\]/u';

    /**
     * Lo que cuenta como «pegado» en los limites de cada patron: letras,
     * cifras y los caracteres de los marcadores (`[`, `]`, `…`). Con `\b` un
     * dato pegado a una letra no casaba en la primera pasada y si en la
     * segunda, cuando la letra ya era `…`: el saneado dejaba de ser idempotente.
     */
    private const string EDGE = '\p{L}\p{N}\[\]\x{2026}';

    /** Primer caracter de uso privado de los marcadores del paso 2. */
    private const int PLACEHOLDER_BASE = 0xE000;

    /** Cuantos identificadores se pueden apartar en un mismo texto (U+E000–U+F8FF). */
    private const int PLACEHOLDER_SLOTS = 0x18FF;

    /**
     * El mensaje, listo para guardarse.
     *
     * Devuelve siempre algo: un mensaje vacio se convierte en un texto que dice
     * que estaba vacio, porque una fila con `message: ''` en el panel parece un
     * fallo del panel.
     */
    public static function sanitize(string $message): string
    {
        $clean = self::bounded($message, self::MAX_LENGTH);

        return $clean === '' ? self::EMPTY_MESSAGE : $clean;
    }

    /**
     * Lo mismo para un valor de `context`, con su techo mas corto.
     *
     * Mismo saneado y no uno mas laxo: el contexto viaja al mismo sitio y lo
     * escribe el mismo cliente en el que no se confia.
     */
    public static function sanitizeContextValue(string $value): string
    {
        $clean = self::bounded($value, self::MAX_CONTEXT_LENGTH);

        return $clean === '' ? self::EMPTY_MESSAGE : $clean;
    }

    /**
     * Los seis pasos, sin colapsar espacios ni techo (ADR-048).
     *
     * Es lo que aplica el log tecnico a su mensaje y al de cada excepcion: una
     * linea de log de 3000 caracteres es legitima y una traza con saltos de
     * linea sigue siendo legible en `stderr`.
     */
    public static function redactText(string $text): string
    {
        [$protected, $kept] = self::protect(self::withoutReservedCharacters($text));

        return self::restore(ErrorTextAllowlist::apply(self::patterns($protected)), $kept);
    }

    /**
     * Solo los patrones: sin vocabulario, sin colapsar espacios, sin techo y
     * sin texto de relleno (L1, regla dura 21).
     *
     * Es lo que aplica el log tecnico a los valores de su contexto (D3, ver el
     * docblock de la clase).
     *
     * **Falla cerrado.** Si una expresion no se puede evaluar —un texto con
     * bytes UTF-8 invalidos, el limite de retroceso de PCRE—, `preg_replace`
     * devuelve `null` y aqui se convierte en cadena vacia: se pierde el texto,
     * no se deja pasar sin sanear. Quien llama decide que escribir en su lugar.
     */
    public static function redact(string $text): string
    {
        [$protected, $kept] = self::protect(self::withoutReservedCharacters($text));

        return self::restore(self::patterns($protected), $kept);
    }

    /**
     * Todo UUID del texto, sustituido por `[uuid]` (H2).
     *
     * Lo aplica el colector del paquete **anonimizado**: el `employee_uuid` es
     * un seudonimo (art. 4.5 RGPD) cuya correspondencia tiene el hotel, y un
     * mensaje como «Employee 0199… has no open shift entry» lo llevaria dentro
     * aunque la columna se omita.
     */
    public static function withoutUuids(string $text): string
    {
        return self::replace('/(?<![0-9A-Za-z])'.self::UUID_PATTERN.'(?![0-9A-Za-z])/u', self::UUID, $text);
    }

    /**
     * Los seis pasos con techo, sin texto de relleno: vacio si no queda nada.
     * Es el UNICO recorte del historico de errores; las columnas lo usan sin
     * indicador (`$ellipsis = ''`) porque en ellas no cabe un `…` que
     * nadie ha escrito.
     *
     * Si hay que truncar, lo truncado **se vuelve a filtrar**: el corte puede
     * dejar media palabra (`Connec…`), y sin la segunda pasada el colector, que
     * vuelve a sanear al leer, la convertiria en `…` y dejaria de ser
     * idempotente. La segunda pasada solo puede acortar —cambia palabras por
     * `…` y cifras por `[n]`—, asi que el techo se sigue cumpliendo.
     */
    public static function bounded(string $text, int $limit, string $ellipsis = '…'): string
    {
        $clean = trim(self::redactText(self::collapse(self::withoutReservedCharacters($text))));

        if (mb_strlen($clean) > $limit) {
            $clean = self::truncate(trim(self::redactText(self::truncate($clean, $limit, $ellipsis))), $limit, $ellipsis);
        }

        return $clean;
    }

    /**
     * El paso 3. El orden importa y es este:
     *
     * 1. **SQL interpolado**, lo primero: la consulta ENTERA es el problema.
     * 2. **Secretos** (`Bearer …`, `clave=valor`): un token lleva dentro cadenas
     *    que parecen otras cosas, y partirlo con otra regla dejaria trozos.
     * 3. **Correos**, antes que los numeros: `ana.ruiz+turno@hotel.es` tiene
     *    cifras que otra regla podria comerse dejando el dominio a la vista.
     * 4. **IBAN, codigo de empleado, pasaporte, documentos y NAF**, de lo mas
     *    largo a lo mas corto.
     * 5. **Fechas y horas**, antes que telefonos e IP: `2026-10-01 12:30` no es
     *    ni lo uno ni lo otro.
     * 6. **IP y telefonos.**
     * 7. **Lo entrecomillado**, al final: es la regla mas destructiva y se aplica
     *    sobre lo que las demas ya han marcado.
     */
    private static function patterns(string $text): string
    {
        $clean = self::sql($text);
        $clean = self::secrets($clean);
        $clean = self::emails($clean);
        $clean = self::ibans($clean);
        $clean = self::employeeCodes($clean);
        $clean = self::passports($clean);
        $clean = self::documents($clean);
        $clean = self::socialSecurityNumbers($clean);
        $clean = self::ipAddresses($clean);
        $clean = self::instants($clean);
        $clean = self::phones($clean);

        return self::quoted($clean);
    }

    /**
     * El UNICO sitio donde se evalua una sustitucion fija, y donde se decide el
     * fallo cerrado: `null` (patron que no se puede evaluar) pasa a cadena
     * vacia, y las reglas que vienen detras trabajan sobre la cadena vacia.
     */
    private static function replace(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text) ?? '';
    }

    /**
     * Quita los caracteres de uso privado, que el paso 2 usa como marcadores
     * (sin esto, un texto que ya trajera uno podria hacerse pasar por un
     * identificador protegido), y los de control salvo el tabulador y los
     * saltos de linea: PostgreSQL rechaza un byte nulo en una columna de
     * texto, y una clase anonima de PHP lo lleva en el nombre. Una fila que no
     * se puede guardar es un error que se pierde.
     */
    private static function withoutReservedCharacters(string $text): string
    {
        return self::replace('/[\x{E000}-\x{F8FF}\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }

    /**
     * El paso 2: aparta lo que tiene que llegar intacto y deja en su lugar un
     * caracter de uso privado. Si no quedan marcadores libres, el resto se deja
     * sin apartar, y los patrones y la lista blanca lo trataran como a
     * cualquier otro texto: se pierde un identificador, no se filtra nada.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function protect(string $text): array
    {
        $kept = [];

        $protected = preg_replace_callback(
            self::PROTECTED,
            static function (array $match) use (&$kept): string {
                if (($match['secret'] ?? '') !== '') {
                    return '[secret]';
                }

                if (\count($kept) >= self::PLACEHOLDER_SLOTS) {
                    return $match[0];
                }

                $placeholder = mb_chr(self::PLACEHOLDER_BASE + \count($kept), 'UTF-8');
                $kept[$placeholder] = $match[0];

                return $placeholder;
            },
            $text,
        );

        return $protected === null ? ['', []] : [$protected, $kept];
    }

    /**
     * El paso 6.
     *
     * @param  array<string, string>  $kept
     */
    private static function restore(string $text, array $kept): string
    {
        return $kept === [] ? $text : strtr($text, $kept);
    }

    /**
     * Corta por caracteres y no por bytes.
     *
     * `substr()` sobre UTF-8 parte una tilde por la mitad y deja un byte
     * invalido que revienta al serializar el JSON del paquete de diagnostico —el
     * peor sitio para descubrirlo—.
     */
    private static function truncate(string $text, int $limit, string $ellipsis): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // El indicador cuenta dentro del techo: la columna no admite ni un
        // caracter mas.
        return mb_substr($text, 0, $limit - mb_strlen($ellipsis)).$ellipsis;
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
     * `QueryException::getMessage()` de Laravel pega al final del mensaje la
     * consulta **con los parametros ya interpolados y sin comillas**. El
     * enganche de captacion compone el mensaje con `getSql()` —los `?` sin
     * enlazar—, asi que en el camino normal esto no llega a activarse. Existe
     * porque **el camino normal no es el unico**.
     *
     * 1. **Todo lo que sigue a `, SQL: ` se corta.**
     * 2. **El `DETAIL: Failing row contains (…)`**, que vuelca la fila entera,
     *    pasa a `Failing row contains [redacted]`.
     * 3. **El `DETAIL: Key (columna)=(valor)`**, anidado incluido (F4c-2), pierde
     *    las dos partes hasta la frase con la que PostgreSQL cierra el DETAIL.
     */
    private static function sql(string $text): string
    {
        $text = self::replace('/,\s*SQL:\s.*/su', '', $text);

        $text = self::replace(
            '/(?<![\p{L}])Failing row contains(?![\p{L}]).*/su',
            'Failing row contains [redacted]',
            $text,
        );

        return self::replace(
            '/(?<![\p{L}])(key)\s*\((?=[^=]*\)\s*=\s*\().*?(?=\s+(?:already exists|conflicts with|is not present|is still referenced)\b|$)/isu',
            "\$1 ('…')=('…')",
            $text,
        );
    }

    private static function secrets(string $text): string
    {
        // Un payload de credencial completo (regla dura 10). El paso 2 ya los
        // ha convertido; se repite porque `redact()` es publico y una regla
        // que solo existe en un sitio es una regla que alguien quita.
        $text = self::replace('/(?<![\p{L}])FH1\.[A-Za-z0-9._~+\/-]+=*/u', '[secret]', $text);

        $text = self::replace('/(?<![\p{L}])Bearer\s+[A-Za-z0-9._~+\/-]+=*/iu', '[secret]', $text);

        return self::replace(
            '/(?<![\p{L}])('.self::SECRET_NAMES.')(?![\p{L}])(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/iu',
            '$1$2[secret]',
            $text,
        );
    }

    /**
     * Correos, tambien con tildes y otras escrituras en la parte local y en el
     * dominio (`josé.núñez@hotel.es`).
     */
    private static function emails(string $text): string
    {
        return self::replace(
            '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u',
            '[email]',
            $text,
        );
    }

    /**
     * DNI y NIE espanoles: ocho cifras y una letra, o `X`/`Y`/`Z`, siete cifras
     * y una letra, en mayusculas o minusculas, compactos o con separadores.
     *
     * **No se comprueba la letra de control** a proposito: el objetivo no es
     * validar documentos sino que no salgan. El DNI sin letra lo atrapa la regla
     * de las siete cifras de {@see ErrorTextAllowlist}.
     */
    private static function documents(string $text): string
    {
        // Compacto o con un separador: `12345678Z`, `12345678-Z`, `X1234567L`,
        // `x-1234567-l`.
        $text = self::replace('/(?<!['.self::EDGE.'])(?:[XYZxyz][ .\-]?)?\d{7,8}[ .\-]?[A-Za-z](?!['.self::EDGE.'])/u', '[id]', $text);

        // Con separadores de miles, como se teclea a mano (F4c-2):
        // `12.345.678-Z`, `12 345 678 Z`, `x 1.234.567 l`.
        return self::replace(
            '/(?<!['.self::EDGE.'._\-])(?:[XYZxyz][ .\-]?)?\d{1,2}[ .]\d{3}[ .]\d{3}[ .\-]?[A-Za-z](?!['.self::EDGE.'_])/u',
            '[id]',
            $text,
        );
    }

    /**
     * Numero de afiliacion a la Seguridad Social (NAF): dos cifras de
     * provincia, siete u ocho de numero y dos de control, compacto o con
     * espacio, barra o guion.
     */
    private static function socialSecurityNumbers(string $text): string
    {
        return self::replace(
            '/(?<!['.self::EDGE.'.\-\/])\d{2}[ \/\-]?\d{7,8}[ \/\-]?\d{2}(?!['.self::EDGE.'.\-\/])/u',
            '[id]',
            $text,
        );
    }

    /**
     * IBAN de cualquier pais, **en mayusculas o en minusculas**, compacto o en
     * grupos de cuatro separados por un espacio o un guion.
     *
     * El falso positivo que impedia aceptar minusculas y guiones —una huella
     * hexadecimal, un UUID— ya no puede ocurrir: el paso 2 los aparta antes.
     * Para no comerse la palabra que viene detras («… 1332 rechazada»), los
     * grupos tras el primero tienen que llevar alguna cifra, y el total tiene
     * que sumar diez cifras o mas.
     */
    private static function ibans(string $text): string
    {
        return preg_replace_callback(
            '/(?<!['.self::EDGE.'])[A-Za-z]{2}\d{2}[ \-]?[A-Za-z0-9]{4}'
            .'(?:[ \-]?(?=[A-Za-z]*\d)[A-Za-z0-9]{4}){2,6}(?:[ \-]?(?=[A-Za-z]*\d)[A-Za-z0-9]{1,3})?(?!['.self::EDGE.'])/u',
            static fn (array $match): string => preg_match_all('/\d/', $match[0]) >= 10 ? '[iban]' : $match[0],
            $text,
        ) ?? '';
    }

    /**
     * El codigo de empleado (PR12, F4c-2): un identificador directo y la mitad
     * publica de la credencial del portal (ADR-015).
     *
     * 1. **Detras de su etiqueta**, ampliada (ADR-048): `employee_code=739104`,
     *    `codigo de empleado: AB12`, y tambien solo «codigo», «código» o «code»
     *    cuando lo que sigue lleva alguna cifra. Es lo que cubre el mensaje de
     *    `EmployeeCodeAlreadyTaken` de versiones anteriores.
     * 2. **La forma canonica** en mayusculas: `E` mas ocho o nueve mayusculas y
     *    cifras con al menos una cifra, o nueve letras todas del alfabeto sin
     *    ambiguos (un 6,8 % de los codigos generados no lleva cifras).
     * 3. **La forma canonica en minusculas**, con al menos una cifra: sin cifra
     *    seria una palabra cualquiera, y de esas se encarga el vocabulario.
     *
     * Un codigo heredado sin etiqueta lo atrapan las reglas de cifras de
     * {@see ErrorTextAllowlist}.
     */
    private static function employeeCodes(string $text): string
    {
        // La etiqueta completa: con `=`, `:` o `#` el valor cae lleve o no
        // cifras, porque solo puede ser un codigo.
        $text = self::replace(
            '/(?<![\p{L}])(employee[_ \-]?code|c(?:o|\x{00F3})digo(?:[_ ]de)?[_ ]empleado)(\s*[=:#]\s*|\s+(?=\S*\d))(?![^\s\[\]\x{2026}]*[\[\]\x{2026}])(\S+)/iu',
            '$1$2[code]',
            $text,
        );

        // La etiqueta corta, solo si lo que sigue tiene forma de codigo: letra Y
        // cifra, o cuatro cifras o mas. Sin esa condicion «code is required»
        // perderia la palabra, y «status code 500» —el mensaje de axios— el
        // estado HTTP, que no identifica a nadie.
        $text = self::replace(
            '/(?<![\p{L}])(c(?:o|\x{00F3})digo|code)(\s*[=:#]\s*|\s+)(?=\S*\p{L}\S*\d|\S*\d\S*\p{L}|\S*\d{4})(?!\S*[\[\]\x{2026}])(\S+)/iu',
            '$1$2[code]',
            $text,
        );

        $text = self::replace(
            '/(?<!['.self::EDGE.'])E(?=[A-Z0-9]{8,9}(?!['.self::EDGE.']))(?:(?=[A-Z0-9]*\d)[A-Z0-9]{8,9}|[ABCDEFGHJKMNPQRSTUVWXYZ]{9})(?!['.self::EDGE.'])/u',
            '[code]',
            $text,
        );

        return self::replace('/(?<!['.self::EDGE.'])e(?=[a-z0-9]*\d)[a-z0-9]{8,9}(?!['.self::EDGE.'])/u', '[code]', $text);
    }

    /**
     * Pasaportes (F4c-2): el espanol son tres letras y seis cifras, cualquiera
     * detras de la palabra, y **los extranjeros sin etiqueta** (ADR-048): de una
     * a tres letras y de seis a nueve cifras, en cualquier caja.
     */
    private static function passports(string $text): string
    {
        $text = self::replace(
            '/(?<![\p{L}])(passport|pasaporte)((?:\s*(?:no\.?|n\x{00BA}|n\x{00B0}|number|n(?:u|\x{00FA})mero))?(?:\s*[=:#]\s*|\s+(?=\S*\d)))(\S+)/iu',
            '$1$2[id]',
            $text,
        );

        return self::replace('/(?<!['.self::EDGE.'])[A-Za-z]{1,3}\d{6,9}(?!['.self::EDGE.'])/u', '[id]', $text);
    }

    /**
     * Telefonos: con prefijo internacional (`+34`, `0034`, `+44 20 …`) y de seis
     * a doce cifras detras, o nacionales de nueve cifras en cualquier
     * agrupacion (3-3-3, 2-3-2-2, 3-2-2-2, 2-2-2-2-1…). Las formas que se
     * escapen las atrapa la regla de las siete cifras.
     */
    private static function phones(string $text): string
    {
        $text = self::replace(
            '/(?<!['.self::EDGE.'+])(?:\+|00)\d{1,3}(?:[ .\-]?\d){6,12}(?!['.self::EDGE.'])/u',
            '[phone]',
            $text,
        );

        return self::replace(
            '/(?<!['.self::EDGE.'.\-])\d(?:[ .\-]?\d){8}(?!['.self::EDGE.'.\-])/u',
            '[phone]',
            $text,
        );
    }

    /**
     * Direcciones IP v4 y v6 (ADR-048, decision conservadora): la del movil de
     * un empleado en el portal es un dato personal.
     *
     * La v6 exige `::` o los ocho grupos, y no admite que empiece pegada a una
     * letra: `Handler::method` no es una direccion.
     */
    private static function ipAddresses(string $text): string
    {
        $text = self::replace('/(?<![\p{N}.\]])\d{1,3}(?:\.\d{1,3}){3}(?!\.?[\p{N}\[])/u', '[ip]', $text);

        return self::replace(
            '/(?<!['.self::EDGE.':])(?=[0-9a-f:]*[0-9a-f])(?:(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}'
            .'|(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?)(?!['.self::EDGE.':])/iu',
            '[ip]',
            $text,
        );
    }

    /**
     * Fechas e instantes completos primero, horas sueltas despues.
     *
     * **Una hora en un mensaje de error es sospechosa de ser una hora de
     * fichaje**, y las horas de fichaje de una persona son datos de jornada.
     */
    private static function instants(string $text): string
    {
        $text = self::replace(
            '/\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+\-]\d{2}:?\d{2})?)?/',
            '[time]',
            $text,
        );

        // Limites de CIFRA y no de palabra (`\b`): una hora pegada a una letra
        // —`T22:00:00Z`, `10:30am`, `x22:15`— tambien es una hora, y con `\b`
        // escapaba (y el resultado dejaba de ser idempotente).
        $text = self::replace('/(?<![\p{N}\/])\d{4}\/\d{1,2}\/\d{1,2}(?![\p{N}\/])/u', '[time]', $text);
        $text = self::replace('/(?<![\p{N}\/])\d{1,2}\/\d{1,2}\/\d{2,4}(?![\p{N}\/])/u', '[time]', $text);

        // `dd-mm-aaaa` y `dd.mm.aaaa` (F4c-2), con el MISMO separador las dos
        // veces y dia, mes y siglo plausibles.
        $text = self::replace(
            '/(?<![\p{N}.\-])(?:0?[1-9]|[12]\d|3[01])([\-.])(?:0?[1-9]|1[0-2])\1(?:19|20)\d{2}(?![\p{N}])/u',
            '[time]',
            $text,
        );

        // `22.30` solo detras de «a las» o «at»: suelto es una version o un
        // decimal.
        $text = self::replace('/(?<![\p{L}])(a las|at)(\s+)\d{1,2}[.h]\d{2}(?![\p{N}])/iu', '$1$2[time]', $text);

        // `hh:mm[:ss[.fff]]`, con la `T` de ISO delante, zona o am/pm detras.
        // No detras de `:` ni de `.`: `app.js:1:12` es una posicion y no una hora.
        return self::replace(
            '/(?<!['.self::EDGE.':.])T?\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+\-]\d{2}:?\d{2}|\s?[ap]\.?m\.?)?(?!['.self::EDGE.'])/iu',
            '[time]',
            $text,
        );
    }

    /**
     * Todo lo entrecomillado, sin mirar dentro, con **las siete formas de
     * comillas** (ADR-048): rectas dobles y simples, angulares `«»`, inglesas
     * `“”` y `‘’`, alemanas `„“`, simples angulares `‹›` y comillas invertidas.
     */
    private static function quoted(string $text): string
    {
        $text = self::replace('/"[^"]*"/u', "'…'", $text);
        $text = self::replace('/\x{00AB}[^\x{00BB}]*\x{00BB}/u', "'…'", $text);
        $text = self::replace('/\x{201E}[^\x{201C}\x{201D}]*[\x{201C}\x{201D}]/u', "'…'", $text);
        $text = self::replace('/\x{201C}[^\x{201D}]*\x{201D}/u', "'…'", $text);
        $text = self::replace('/\x{2018}[^\x{2019}]*\x{2019}/u', "'…'", $text);
        $text = self::replace('/\x{2039}[^\x{203A}]*\x{203A}/u', "'…'", $text);
        $text = self::replace('/`[^`]*`/u', "'…'", $text);

        return self::replace('/\'[^\']*\'/u', "'…'", $text);
    }
}
