<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Los festivos de un centro: una lista de fechas ISO `AAAA-MM-DD`, ordenada y
 * sin repetidos (`compliance_profiles.holiday_calendar`, RF-PD-07).
 *
 * ## Por que existe, y que problema real cerro
 *
 * Antes de esta clase el mismo parseo estaba **cuatro veces** —dos
 * `decodeCalendar()` en dos adaptadores y dos `isRealDate()` en dos objetos de
 * valor— y las copias ya no decian lo mismo: el borde HTTP rechazaba un festivo
 * repetido con un `422` y el nucleo lo deduplicaba en silencio. Peor: una fila
 * editada a mano con `'["navidad"]'` pasaba el filtro del adaptador —que solo
 * miraba que fueran cadenas— y hacia estallar al objeto de valor **dentro de la
 * pasada nocturna de deteccion**, que resuelve la politica antes del bucle y sin
 * `try`. El resultado era que un festivo mal escrito dejaba sin evaluar RN-10 y
 * RN-11 de toda la instalacion, y tumbaba tambien la purga por retencion. Un
 * dato decorativo no puede apagar dos reglas legales.
 *
 * ## Una politica por camino, y las dos escritas aqui
 *
 * - **Al LEER es tolerante** ({@see self::of()} y {@see self::fromStoredJson()}):
 *   lo que no tenga forma de fecha se **descarta** y se deja anotado en
 *   {@see self::$rejected}; los repetidos se colapsan y se anota en
 *   {@see self::$hadDuplicates}. Nunca lanza. Es la misma decision que la tarea
 *   5.1 tomo para la configuracion de la instalacion y la exige la regla dura 19:
 *   una fila corrupta no puede dejar a un centro sin calcular.
 * - **Al ESCRIBIR es estricta**: quien recibe la peticion mira `rejected` y
 *   `hadDuplicates` y responde `422` señalando el campo. Ahi si hay alguien
 *   delante a quien decirselo, y guardar en silencio algo distinto de lo que se
 *   envio es como se acaba con un calendario que nadie sabe por que no cuadra.
 *
 * **El descarte no es silencioso en ninguno de los dos casos**: al leer lo
 * publica quien construye la politica, en un `warning` con el identificador del
 * perfil y **sin dato personal** (un festivo no lo es, pero la regla dura 21
 * gobierna el habito).
 *
 * Vive en `Shared/Domain` porque lo necesitan `Attendance` —a traves de
 * {@see CompliancePolicy}— y `Product`, que es quien tiene la tabla, y ninguno
 * de los dos puede depender del otro (doc 02 §1.6, ADR-025).
 */
final readonly class HolidayCalendar
{
    /**
     * @param  list<string>  $days  fechas ISO validas, ordenadas y sin repetir
     * @param  list<string>  $rejected  lo que se descarto por no tener forma de fecha, tal como llego
     * @param  bool  $hadDuplicates  si la entrada traia alguna fecha repetida
     */
    private function __construct(
        public array $days,
        public array $rejected,
        public bool $hadDuplicates,
    ) {}

    public static function empty(): self
    {
        return new self([], [], false);
    }

    /**
     * Normaliza cualquier entrada sin lanzar nunca.
     *
     * Acepta `mixed` a proposito: los dos origenes reales —un `jsonb` leido de la
     * base y un cuerpo JSON de la API— pueden traer cualquier cosa, y obligar a
     * cada uno a comprobar la forma antes de llamar es como se acaba con dos
     * comprobaciones distintas.
     */
    public static function of(mixed $value): self
    {
        if (! is_array($value)) {
            return self::empty();
        }

        // Un objeto JSON no es un calendario. Se descarta entero en vez de
        // quedarse con sus valores: `{"navidad": "2026-12-25"}` no describe una
        // lista de festivos, y sacar de ahi una fecha seria adivinar. Queda
        // anotado para que el descarte no sea silencioso.
        if (! array_is_list($value)) {
            return new self([], ['object'], false);
        }

        $days = [];
        $rejected = [];

        foreach ($value as $day) {
            if (! is_string($day) || ! self::isIsoDate($day)) {
                $rejected[] = is_string($day) ? $day : get_debug_type($day);

                continue;
            }

            $days[] = $day;
        }

        // `sort()` reindexa por si mismo, asi que un `array_values()` delante
        // seria codigo muerto: se deja fuera a proposito.
        $unique = array_unique($days);

        // Ordenado, para que dos calendarios con los mismos festivos en distinto
        // orden sean el mismo calendario y reordenar la lista no se lea como un
        // cambio de umbral legal en el asiento de auditoria.
        sort($unique);

        return new self($unique, $rejected, count($unique) !== count($days));
    }

    /**
     * Lo mismo, a partir del texto que PostgreSQL devuelve por una columna
     * `jsonb`.
     *
     * Un JSON ilegible se lee como calendario vacio y no como un error, por lo
     * mismo que arriba.
     */
    public static function fromStoredJson(string $raw): self
    {
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        return self::of($decoded);
    }

    /** Si hubo algo que corregir: sirve para decidir si hay que avisar o rechazar. */
    public function isClean(): bool
    {
        return $this->rejected === [] && ! $this->hadDuplicates;
    }

    private static function isIsoDate(string $day): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return false;
        }

        [$year, $month, $dayOfMonth] = array_map(intval(...), explode('-', $day));

        return checkdate($month, $dayOfMonth, $year);
    }
}
