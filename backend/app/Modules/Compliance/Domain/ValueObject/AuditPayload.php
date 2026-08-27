<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\AuditPayloadIsNotCanonical;

/**
 * El `payload` de una entrada de auditoria y su **serializacion canonica**
 * (doc 02 §7.4, `/revision-cumplimiento` bloque C).
 *
 * **Por que hace falta que sea canonica.** El hash se calcula sobre el texto del
 * payload. Si el mismo hecho puede producir dos textos distintos —porque las
 * claves se insertaron en otro orden, porque un `json_encode` escapo `/` y otro
 * no, porque una version de PHP separo con espacios— entonces recalcular la
 * cadena manana da otro resultado y el verificador denuncia una manipulacion que
 * nunca ocurrio. Una alerta critica que suena sin motivo se acaba silenciando, y
 * con ella se pierde la unica garantia que esta tabla aporta (ADR-027).
 *
 * **Las cuatro decisiones de la forma canonica.**
 *
 * 1. **Orden de claves estable.** Los mapas se ordenan por clave con `ksort` y
 *    `SORT_STRING`, que compara byte a byte y no depende de la configuracion
 *    regional del proceso. Las **listas conservan su orden**: en una lista el
 *    orden es informacion, no presentacion.
 * 2. **Sin espacios variables.** `json_encode` sin `JSON_PRETTY_PRINT`, que es
 *    lo unico que introduce blancos opcionales.
 * 3. **UTF-8 literal.** `JSON_UNESCAPED_UNICODE` y `JSON_UNESCAPED_SLASHES`: el
 *    escapado es opcional en JSON y por tanto una fuente de divergencia. Se fija
 *    el que no escapa. Las cadenas se validan como UTF-8 antes de codificar.
 * 4. **Sin objetos.** Solo `null`, `bool`, `int`, `float` finito, `string` y
 *    arrays de esos tipos. Un objeto se serializa segun como este implementado
 *    **hoy**; el dia que gane una propiedad, el mismo hecho produciria otro
 *    hash. `JSON_PRESERVE_ZERO_FRACTION` fija la unica ambiguedad que queda en
 *    los flotantes (`1.0` frente a `1`).
 *
 * **Que no debe llevar un payload** (regla dura 21, RGPD): ni nombres, ni
 * correos, ni DNI, ni el PIN, ni el token de una credencial. `employee_uuid`,
 * `device_id` y codigos internos bastan para reconstruir cualquier hecho.
 */
final readonly class AuditPayload
{
    /** @var array<array-key, mixed> */
    public array $data;

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function __construct(array $data)
    {
        self::assertCanonicalizable($data, '$payload');

        $this->data = $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function of(array $data): self
    {
        return new self($data);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Reconstruccion desde la columna `JSONB`.
     *
     * **PostgreSQL no conserva el orden de las claves de un `jsonb`** —las
     * almacena ordenadas por longitud y despues por bytes—, asi que el texto
     * que devuelve la base de datos no es el que se encadeno. Da igual: la
     * forma canonica ordena, y ordenar un mapa ya ordenado de otra manera da el
     * mismo resultado. Es precisamente por esto por lo que la canonicalizacion
     * no puede consistir en «guardar el JSON tal cual se genero».
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromStorage(array $data): self
    {
        return new self($data);
    }

    /**
     * Componente `canonical_json(payload)` de la formula del §7.4, con el mismo
     * separador de registro final que los demas componentes.
     */
    public function canonical(): string
    {
        return $this->encode()."\x1e";
    }

    /**
     * El JSON canonico, sin separador. Es tambien lo que se persiste en la
     * columna `payload`: guardar el mismo texto que se encadena hace que
     * inspeccionar la fila a mano y recalcular su hash den lo mismo.
     */
    public function encode(): string
    {
        // Un array vacio de PHP es ambiguo —`[]` o `{}`— y `json_encode` elige
        // `[]`. El payload es un MAPA: la columna es `JSONB NOT NULL DEFAULT
        // '{}'::jsonb`, y una fila con `[]` y otra con `{}` significando lo
        // mismo son dos representaciones del payload vacio. Se fija una.
        if ($this->data === []) {
            return '{}';
        }

        /** @var non-empty-string $json */
        $json = json_encode(
            self::sortRecursively($this->data),
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION,
        );

        return $json;
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * Ordena los mapas por clave y deja las listas como estan.
     *
     * `array_is_list()` es la frontera: `[1, 2, 3]` es una lista y su orden es
     * el dato; `['b' => 1, 'a' => 2]` es un mapa y su orden es accidental.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortRecursively(array $value): array
    {
        if (! array_is_list($value)) {
            // SORT_STRING y no el orden natural: comparacion byte a byte,
            // identica en cualquier maquina y con cualquier `locale`.
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursively($item);
            }
        }

        return $value;
    }

    /**
     * Rechaza en el constructor todo lo que no tiene una unica representacion
     * posible. Se comprueba al construir y no al codificar para que el error
     * aparezca donde se comete —en el modulo que arma la entrada— y no un dia
     * despues, en el verificador.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function assertCanonicalizable(array $value, string $path): void
    {
        foreach ($value as $key => $item) {
            $itemPath = $path.'.'.(is_int($key) ? '['.$key.']' : $key);

            if (is_array($item)) {
                self::assertCanonicalizable($item, $itemPath);

                continue;
            }

            self::assertCanonicalizableLeaf($item, $itemPath);
        }
    }

    /**
     * Un valor que no es array. Separado del recorrido para que ninguno de los
     * dos metodos pase de la complejidad ciclomatica del §3.5, y porque son dos
     * preguntas distintas: «¿esta estructura se puede recorrer?» y «¿este valor
     * tiene una unica representacion posible?».
     */
    private static function assertCanonicalizableLeaf(mixed $item, string $path): void
    {
        if ($item === null || is_bool($item) || is_int($item)) {
            return;
        }

        if (is_float($item)) {
            if (! is_finite($item)) {
                throw AuditPayloadIsNotCanonical::nonFiniteFloat($path);
            }

            return;
        }

        if (is_string($item)) {
            if (! mb_check_encoding($item, 'UTF-8')) {
                throw AuditPayloadIsNotCanonical::invalidUtf8($path);
            }

            return;
        }

        throw AuditPayloadIsNotCanonical::unsupportedValue($path, get_debug_type($item));
    }
}
