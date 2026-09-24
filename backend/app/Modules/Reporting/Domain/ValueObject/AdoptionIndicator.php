<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Un indicador del cuadro de impacto, con su valor, su comparacion y su objetivo
 * (**RF-IN-08**).
 *
 * ## `null` significa «no se sabe», nunca cero
 *
 * Es la invariante de esta clase y la razon por la que existe en lugar de un
 * `array`. Un periodo sin denominador —la instalacion arranco a mitad, el hotel
 * estaba cerrado— **no tuvo un 0 % de jornadas completas**: no tuvo jornadas. Un
 * cero ahi se lee como un desplome y es exactamente el error que este cuadro
 * existe para no cometer, porque es el cuadro con el que se discute una
 * renovacion de licencia.
 *
 * El constructor es privado y {@see self::of()} **deriva** el `delta` en lugar de
 * aceptarlo: asi no existe ninguna forma de construir un indicador con un valor
 * ausente y una variacion inventada.
 *
 * ## El `delta` va en la unidad del indicador, no en porcentaje relativo
 *
 * Puntos porcentuales en `percent`, minutos en `minutes`, unidades en `count`.
 * «Ha subido 2,3 puntos» se comprueba sumando; «ha mejorado un 2,4 %» sobre un
 * porcentaje es la forma habitual de que dos personas entiendan dos cosas
 * distintas.
 */
final readonly class AdoptionIndicator
{
    /**
     * Decimales de la variacion, los mismos que los de los valores que resta.
     *
     * Dos, porque el objetivo de disponibilidad de RNF-D-01 es **99,9 %** y con uno
     * no se distinguiria una mejora de cinco centesimas de ninguna mejora. La misma
     * escala que usa la politica al calcular los valores: con dos escalas distintas,
     * `current - previous` dejaria de cuadrar con lo que la pantalla enseña.
     */
    private const int SCALE = 2;

    private function __construct(
        public AdoptionIndicatorKey $key,
        public AdoptionIndicatorUnit $unit,
        public ?float $current,
        public ?float $previous,
        public ?float $delta,
        public ?AdoptionTarget $target,
    ) {}

    /**
     * El indicador con su variacion **derivada**, nunca recibida.
     *
     * `$previous` llega ya a `null` cuando el periodo anterior no tiene
     * denominador; aqui se vuelve a mirar porque el `delta` no puede existir sin
     * los dos extremos, y quien lo calculara por fuera podria olvidarse de uno.
     *
     * ## La resta se redondea, y no es cosmetica
     *
     * `99.94 - 99.81` en coma flotante binaria da `0.12999999999999545`, y con
     * `serialize_precision = -1` eso es literalmente lo que sale por el JSON: el
     * contrato ejemplifica `0.13` y el cliente recibiria quince decimales de ruido.
     * Los dos extremos vienen ya redondeados a dos decimales, asi que **la unica
     * cifra significativa de su resta cabe en dos**: redondear aqui no pierde nada
     * y evita que cada consumidor —pantalla, CSV, PDF— decida por su cuenta cuantos
     * enseñar.
     *
     * En `minutes` y en `count` la operacion no hace nada: los dos extremos son
     * enteros y su resta tambien.
     */
    public static function of(AdoptionIndicatorKey $key, ?float $current, ?float $previous): self
    {
        $comparable = $key->comparesAgainstPreviousPeriod() ? $previous : null;

        return new self(
            key: $key,
            unit: $key->unit(),
            current: $current,
            previous: $comparable,
            delta: $current === null || $comparable === null ? null : round($current - $comparable, self::SCALE),
            target: $key->target(),
        );
    }

    /**
     * El indicador que **no se compara con nada**: las dos fotos de hoy, el
     * reparto offline y la linea base declarada.
     *
     * Existe para que quien lo construye no tenga que pasar un `null` cuyo
     * significado dependa de leer {@see AdoptionIndicatorKey::comparesAgainstPreviousPeriod()}.
     */
    public static function snapshot(AdoptionIndicatorKey $key, ?float $current): self
    {
        return self::of($key, $current, null);
    }

    /**
     * ¿Alcanza su objetivo del §1.3?
     *
     * `null` cuando no hay valor o cuando el objetivo no juzga nada
     * ({@see AdoptionTargetComparison::Reduction}), y tambien cuando no hay
     * objetivo. **`null` no es «no cumple».**
     */
    public function meetsTarget(): ?bool
    {
        return $this->target?->isMetBy($this->current);
    }
}
