<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Que puede llevar `error_events.context`: **una lista cerrada, derivada de lo
 * que los clientes y el servidor emiten DE VERDAD**, con valores escalares
 * saneados (RF-PD-15, RL-19, regla dura 21, decision 5 de la ficha 5.12).
 *
 * ## La lista se deriva del codigo que reporta, no se inventa
 *
 * Es la correccion de la revision, y el fallo que arregla es el peor posible en
 * esta tabla: la lista original —`status`, `outcome`, `queue_size`, `code`— **no
 * contenia ni una sola clave que un cliente escriba**. El efecto medido: el
 * contexto de todo error de quiosco y de web salia vacio, los errores llegaban
 * sin mensaje y **todos los `web.vue_error` de la instalacion colapsaban en una
 * unica fila**, porque la huella se calcula sobre un mensaje que era siempre el
 * mismo codigo.
 *
 * De donde sale cada bloque de la lista de abajo:
 *
 * - `Product\Infrastructure\Capture`, el enganche del servidor;
 * - las llamadas `.report(...)` y los contextos de diagnostico de
 *   `frontend-kiosk/src`;
 * - `packages/web-kit/src/clientErrors.ts`, que comparten panel y portal.
 *
 * **Y no se confia en que siga siendo cierto**: `ClientErrorContextKeysTest`
 * (`tests/Architecture`) extrae las claves de esos ficheros y las compara con
 * esta lista. Una clave nueva en un cliente sin su fila aqui rompe la suite, que
 * es mucho mejor que descubrirlo por un contexto vacio en la instalacion de un
 * cliente.
 *
 * ## Lista de permitidos, nunca de exclusiones
 *
 * Es la misma decision que {@see FieldAllowlist} —y usa esa misma clase por
 * dentro— por el mismo motivo que escribio la ficha 5.9: *«una lista de
 * exclusiones falla en silencio cada vez que se anade un campo nuevo»*. Aqui es
 * todavia mas claro, porque **quien rellena el contexto es un cliente**: el dia
 * que alguien anada `context: { employee_name: … }` en el panel «para depurar»,
 * con exclusiones ese nombre empezaria a viajar hacia el fabricante dentro del
 * paquete de diagnostico y nadie se enteraria. Con permitidos, la clave se cae
 * y no aparece hasta que alguien la anada aqui a mano, mirandola.
 *
 * ## Las tres identidades tienen columna propia y NO pasan por aqui
 *
 * `trace_id`, `employee_uuid` y `device_id` son columnas de la tabla. No estan
 * en esta lista a proposito: si estuvieran, habria dos sitios donde puede vivir
 * un `employee_uuid` y solo uno de los dos tiene el tipo `uuid` de PostgreSQL
 * detras. Un cliente que las mande dentro del contexto las pierde, y hace bien:
 * quien decide de que dispositivo viene un error es el token, no el cuerpo.
 *
 * ## Solo escalares, y saneados
 *
 * Un valor que sea a su vez un mapa no entra —la lista no puede afirmar nada
 * sobre sus claves, y permitir `meta` colaria el objeto entero que llevara
 * dentro—, y los que entran pasan por {@see ErrorMessageSanitizer} y se truncan
 * a 200 caracteres. Un `reason` es texto libre escrito por quien programo el
 * cliente: puede llevar cualquier cosa dentro, exactamente igual que el mensaje.
 *
 * ## Las claves ausentes se caen, no se rellenan con nulo
 *
 * Al reves que {@see FieldAllowlist::apply()}, que rellena para que soporte
 * distinga «no tenia valor» de «no viaja». Aqui no se puede: el contrato declara
 * `context` con `additionalProperties: string|integer|number|boolean` y **sin
 * nulos**, asi que un `{"route": null, "method": null, …}` con todas las claves
 * en cada fila seria a la vez invalido y ruido. La lista se sigue usando por lo
 * que si aporta: **filtrar**.
 *
 * El orden declarado no sobrevive al viaje: `context` es `jsonb`, y ese tipo
 * ordena las claves por longitud y despues alfabeticamente, sin conservar el
 * orden de insercion. Da igual —un mapa no se lee en orden— pero conviene no
 * prometerlo.
 *
 * ## Dominio puro
 *
 * Sin framework: entra un mapa, sale otro. La prueba unitaria que lo fija manda
 * un contexto con nombre, correo y una estructura anidada y comprueba que no
 * queda nada de eso.
 */
final readonly class ErrorContextAllowlist
{
    /**
     * Las claves, agrupadas por quien las escribe. El agrupamiento es para quien
     * lee este fichero: el orden real de un `jsonb` lo decide PostgreSQL.
     *
     * @var list<string>
     */
    private const array KEYS = [
        // --- Servidor: `Product\Infrastructure\Capture` -----------------------
        // Una peticion de la API.
        'route',
        'method',
        // Un trabajo de cola.
        'job',
        'queue',
        'attempts',
        // El planificador y la consola.
        'command',

        // --- Los tres clientes ------------------------------------------------
        // Comunes a quiosco y web: por que fallo y con que resultado.
        'cause',
        'reason',
        'error_type',
        'http_status',
        // Panel y portal (`packages/web-kit/src/clientErrors.ts`): que componente
        // de Vue, en que gancho, y el fichero:linea del error de `window`.
        'component',
        'hook',
        'source',
        'line',
        // Quiosco (`frontend-kiosk/src`): el ambito del error global y el estado
        // de los subsistemas que pueden dejarlo sin fichar.
        'scope',
        'audio_state',
        'silence_ms',
        'skew_seconds',
        'durable',
        // Quiosco, cola offline y padron: cuantas cosas habia en juego.
        'entries',
        'items',
        'missing',
        'purged',
    ];

    /**
     * La clave que **no** entra en el contexto porque asciende a columna.
     *
     * Los tres reporters —el del quiosco y el de `web-kit`— mandan el texto del
     * error dentro del contexto, con este nombre. El servidor lo saca de ahi y
     * lo guarda en `error_events.message`: es lo que hace que la fila del panel
     * diga algo. Si ademas se quedara en el contexto estaria dos veces, con dos
     * longitudes maximas distintas (1000 y 200), y el paquete de diagnostico lo
     * llevaria duplicado.
     */
    public const string MESSAGE_KEY = 'message';

    /**
     * El contexto listo para guardarse.
     *
     * El filtrado y el orden los hace {@see FieldAllowlist} —la misma clase que
     * el paquete de diagnostico y la exportacion integra, para que no haya dos
     * listas de permitidos con dos comportamientos—; lo que anade este metodo es
     * el saneado de cada valor y la caida de los nulos que aquella deja puestos.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, scalar>
     */
    public static function apply(array $context): array
    {
        /** @var array<string, mixed> $named */
        $named = array_filter($context, static fn (int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);

        $filtered = (new FieldAllowlist(...self::KEYS))->apply($named);

        $allowed = [];

        foreach ($filtered as $key => $value) {
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $allowed[$key] = $value;

                continue;
            }

            if (is_string($value)) {
                $clean = ErrorMessageSanitizer::sanitizeContextValue($value);

                // Un valor que se queda en nada despues del saneado no aporta
                // una clave vacia: aporta ruido.
                if ($clean !== '' && $clean !== '(sin mensaje)') {
                    $allowed[$key] = $clean;
                }

                continue;
            }

            // Nulo, mapa, lista, objeto o recurso: fuera. Ver el docblock.
        }

        return $allowed;
    }

    /**
     * El texto que el cliente mando dentro del contexto, si lo mando.
     *
     * Vive aqui —y no en el `FormRequest`— porque hay **dos** puertas de entrada
     * de errores de cliente: `POST /api/v1/client-errors` para el panel y el
     * portal, y el latido para el quiosco. Con la extraccion escrita dos veces,
     * bastaria olvidarla en una para que la mitad de los errores llegaran sin
     * mensaje, que es exactamente el defecto que esta revision corrige.
     *
     * @param  array<array-key, mixed>  $context
     * @return string|null Nulo si no viene o si viene vacio; quien llama cae al codigo.
     */
    public static function messageIn(array $context): ?string
    {
        $message = $context[self::MESSAGE_KEY] ?? null;

        return is_string($message) && trim($message) !== '' ? $message : null;
    }

    /**
     * Las claves declaradas, en orden. Lo usan el contrato, la documentacion de
     * cliente y la prueba de arquitectura que las ata a los ficheros de los
     * clientes.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return self::KEYS;
    }

    public static function allows(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
