<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Logging;

use App\Modules\Product\Domain\ValueObject\ErrorMessageSanitizer;
use Closure;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * El saneador de `error_events`, aplicado a **cada linea del log tecnico**
 * (L1, regla dura 21, RF-PD-15, RL-08, RL-19).
 *
 * ## El hueco que cierra
 *
 * `error_events` se depuraba; Monolog no. El informe de una excepcion no
 * controlada escribe su `getMessage()` como mensaje de la linea y la excepcion
 * entera en `context.exception`, que el `JsonFormatter` serializa con su mensaje
 * dentro. Una `QueryException` lleva el SQL con los valores interpolados: en la
 * verificacion de la 2.1.0 aparecieron cuatro `employee_code` reales en claro en
 * el log del contenedor, y un INSERT fallido en `employees` habria sacado el
 * nombre y el correo. Ese log lo lee el paquete de diagnostico y se copia en
 * Loki, donde no hay borrado selectivo.
 *
 * ## Que sanea
 *
 * - `message`, por la **lista blanca por palabra** (ADR-048,
 *   `ErrorMessageSanitizer::redactText()`): un nombre sin comillas ya no pasa.
 * - Todo valor `string` de `context`, recorriendo listas y mapas anidados,
 *   **solo por patrones** (`redact()`, decision D3 de ADR-048).
 * - Las **claves** de los mapas anidados, por la lista blanca por palabra,
 *   con sufijo `#2` si dos colisionan.
 * - `context.exception` y cualquier otra `Throwable` del contexto, con toda su
 *   cadena de `getPrevious()`, por la lista blanca. Si ningun mensaje de la cadena cambia al
 *   sanearlo, la excepcion **se deja como esta** y el formateador la serializa
 *   igual que siempre. Si alguno cambia, se sustituye por un mapa con **la
 *   misma forma** que produce `NormalizerFormatter::normalizeException()` con
 *   las trazas desactivadas, que es como las escribe el canal `stderr`
 *   (`class`, `message`, `code`, `file`, `previous`): el JSON no cambia de
 *   forma, solo pierde el dato.
 *
 * ## Que NO toca
 *
 * - `extra`, donde solo quedan los identificadores de correlacion que deja
 *   `CorrelationOnlyExtra`. El `trace_id` sale intacto, que es la condicion
 *   para que el log siga sirviendo.
 * - Esos mismos identificadores cuando vienen en `context`
 *   ({@see self::CORRELATION_KEYS}) **y tienen su forma** (UUID, traza de 32
 *   hexadecimales, `traceparent` del W3C): no hay nada que sanear. Con otra
 *   forma se sanean como cualquier valor (ADR-048).
 * - Objetos que no sean `Throwable`. El formateador los serializa por su
 *   cuenta y aqui no se puede afirmar nada sobre su forma; ningun punto del
 *   producto mete un objeto con datos personales en el contexto de un log.
 *
 * ## Nunca tumba la linea ni la peticion (regla dura 19)
 *
 * Un processor que lanza deja la linea sin escribir —y el sitio donde duele es
 * el informe de la excepcion que se estaba registrando— o, en la pila con
 * `ignore_exceptions: false`, tumba la peticion. Ante cualquier fallo la linea
 * se escribe igual, con nivel, canal y `extra` (y por tanto `trace_id`), pero
 * con el mensaje sustituido por {@see self::FAILED} y el contexto vacio: **se
 * falla cerrado**. Dejarla pasar sin sanear seria cambiar un fallo raro por
 * una fuga.
 *
 * ## Donde corre
 *
 * El *tap* {@see RedactPersonalData} lo coloca **el ultimo** de la cadena del
 * canal, detras de `PsrLogMessageProcessor`, para que el mensaje ya
 * interpolado tambien pase por aqui. En el canal `emergency`, que Laravel monta
 * a mano sin leer `tap`, lo pone `RedactingLogManager`.
 */
final readonly class RedactPersonalDataProcessor implements ProcessorInterface
{
    /** Lo que se escribe en lugar del mensaje si el saneado falla. */
    public const string FAILED = '[log redaction failed]';

    /** Lo que se escribe en lugar de un texto que el saneado vacio por completo. */
    public const string REDACTED = '[redacted]';

    /**
     * Las claves de correlacion del doc 02 §8.1, que no se sanean.
     *
     * @var list<string>
     */
    public const array CORRELATION_KEYS = ['trace_id', 'traceparent', 'scan_id', 'device_id', 'employee_uuid'];

    /**
     * Profundidad maxima que se recorre. Mas abajo el valor se sustituye: no
     * se puede afirmar que no lleve nada, y un contexto tan anidado no
     * diagnostica nada que el nivel de arriba no diga.
     */
    private const int MAX_DEPTH = 8;

    /**
     * La forma que tiene que tener cada clave de correlacion para pasar sin
     * tocarse (ADR-048). Con otra forma se sanea como cualquier valor: que la
     * clave se llame `employee_uuid` no garantiza que lleve un UUID.
     *
     * @var array<string, non-empty-string>
     */
    private const array CORRELATION_SHAPES = [
        'trace_id' => '/^[0-9a-f]{32}$/',
        'traceparent' => '/^[0-9a-f]{2}-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/',
        'scan_id' => self::UUID,
        'device_id' => self::UUID,
        'employee_uuid' => self::UUID,
    ];

    private const string UUID = '/^'.ErrorMessageSanitizer::UUID_PATTERN.'$/';

    /** @var Closure(string): string */
    private Closure $redact;

    /** @var Closure(string): string */
    private Closure $redactText;

    /**
     * @param  (Closure(string): string)|null  $redact  El saneado por patrones de los valores del contexto. Se inyecta en las pruebas para forzar un fallo; en produccion es {@see ErrorMessageSanitizer::redact()}.
     * @param  (Closure(string): string)|null  $redactText  La lista blanca por palabra de los mensajes y de las claves anidadas. Si no se da y se da `$redact`, se usa ese (las pruebas de fallo); en produccion es {@see ErrorMessageSanitizer::redactText()}.
     */
    public function __construct(?Closure $redact = null, ?Closure $redactText = null)
    {
        $this->redact = $redact ?? ErrorMessageSanitizer::redact(...);
        $this->redactText = $redactText ?? $redact ?? ErrorMessageSanitizer::redactText(...);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            return $record->with(
                message: $this->message($record->message),
                context: $this->map($record->context, 0),
            );
        } catch (Throwable) {
            return $record->with(message: self::FAILED, context: []);
        }
    }

    /**
     * Un mensaje —el de la linea o el de una excepcion—: por la lista blanca
     * por palabra (ADR-048). Es texto que llega de fuera tanto como del codigo.
     */
    private function message(string $value): string
    {
        return $this->cleaned($value, $this->redactText);
    }

    /**
     * Un valor de texto del contexto: solo patrones (D3). Lo escribe el codigo
     * del producto, y el vocabulario estropearia rutas, clases y nombres de
     * trabajo en un log que no sale de la instalacion.
     */
    private function text(string $value): string
    {
        return $this->cleaned($value, $this->redact);
    }

    /**
     * @param  Closure(string): string  $redact
     */
    private function cleaned(string $value, Closure $redact): string
    {
        if ($value === '') {
            return '';
        }

        $clean = $redact($value);

        // El saneado falla cerrado devolviendo cadena vacia: no se deja la
        // linea muda, se dice que habia algo y se quito.
        return $clean === '' ? self::REDACTED : $clean;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function map(array $values, int $depth): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if ($depth === 0 && $this->isCorrelation($key, $value)) {
                $clean[$key] = $value;

                continue;
            }

            $clean[$this->key($key, $depth, $clean)] = $this->value($value, $depth);
        }

        return $clean;
    }

    /**
     * Una clave de correlacion **con su forma** (ADR-048).
     */
    private function isCorrelation(int|string $key, mixed $value): bool
    {
        if (! \is_string($key) || ! isset(self::CORRELATION_SHAPES[$key])) {
            return false;
        }

        return \is_string($value) && preg_match(self::CORRELATION_SHAPES[$key], $value) === 1;
    }

    /**
     * La clave de un mapa ANIDADO (ADR-048).
     *
     * Las del primer nivel las escribe el codigo del producto
     * (`'reason' => …`); las de mas abajo pueden venir de los datos —un mapa
     * indexado por nombre, una fila entera—, asi que pasan por la lista blanca
     * por palabra. Si dos claves distintas quedan iguales al sanearlas, la
     * segunda lleva `#2` (y la tercera `#3`): perder un valor seria esconder
     * diagnostico.
     *
     * @param  array<array-key, mixed>  $taken
     */
    private function key(int|string $key, int $depth, array $taken): int|string
    {
        if ($depth === 0 || \is_int($key)) {
            return $key;
        }

        $clean = $this->message($key);
        $candidate = $clean;

        for ($suffix = 2; \array_key_exists($candidate, $taken); $suffix++) {
            $candidate = $clean.'#'.$suffix;
        }

        return $candidate;
    }

    private function value(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            return $this->text($value);
        }

        if (is_array($value)) {
            return $depth >= self::MAX_DEPTH ? self::REDACTED : $this->map($value, $depth + 1);
        }

        if ($value instanceof Throwable) {
            return $this->throwable($value);
        }

        return $value;
    }

    /**
     * La excepcion tal cual si no habia nada que quitar; si lo habia, su forma
     * normalizada con los mensajes saneados.
     *
     * @return Throwable|array<string, mixed>
     */
    private function throwable(Throwable $exception): Throwable|array
    {
        $changed = false;
        $normalized = $this->normalize($exception, 0, $changed);

        return $changed ? $normalized : $exception;
    }

    /**
     * La forma de `NormalizerFormatter::normalizeException()` sin traza.
     *
     * @return array<string, mixed>
     */
    private function normalize(Throwable $exception, int $depth, bool &$changed): array
    {
        $message = $exception->getMessage();
        $clean = $this->message($message);
        $changed = $changed || $clean !== $message;

        $data = [
            'class' => $exception::class,
            'message' => $clean,
            'code' => $this->codeOf($exception),
            'file' => $exception->getFile().':'.$exception->getLine(),
        ];

        $previous = $exception->getPrevious();

        if ($previous instanceof Throwable) {
            $data['previous'] = $depth >= self::MAX_DEPTH
                ? self::REDACTED
                : $this->normalize($previous, $depth + 1, $changed);
        }

        return $data;
    }

    /**
     * El mismo `(int)` que el formateador. `getCode()` de una `QueryException`
     * es la cadena del `SQLSTATE`, no un entero.
     */
    private function codeOf(Throwable $exception): int
    {
        $code = $exception->getCode();

        return is_int($code) ? $code : (is_numeric($code) ? (int) $code : 0);
    }
}
