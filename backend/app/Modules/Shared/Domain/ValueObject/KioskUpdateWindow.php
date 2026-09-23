<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * La franja en la que una tablet **puede** aplicar una version nueva de la PWA
 * (**RF-KI-07**, ajuste `KIOSK_UPDATE_WINDOW`, tarea 3.12).
 *
 * ## Es una ventana de PERMISO, no de bloqueo
 *
 * Fuera de ella el quiosco sigue fichando, sigue encolando y sigue sincronizando:
 * lo unico que no hace es recargarse (regla dura 19). Una actualizacion a las
 * 06:00 con treinta personas delante es exactamente el fallo que RF-KI-07 existe
 * para evitar, y el que el doc 05 §10.4 promete que no ocurre.
 *
 * ## La declara el cliente; el producto no la adivina
 *
 * RF-KI-07 dice «ventana de actualizacion **configurable**», asi que la franja
 * es configuracion por centro y no una inferencia sobre el historico de escaneos
 * (regla dura 13, ADR-017). Intentar deducir el cambio de turno y equivocarse
 * significa recargar la tablet con cola de gente delante.
 *
 * ## Hora LOCAL del centro, y no UTC
 *
 * Es el unico dato del producto que se declara en hora local a proposito: quien
 * lo escribe en el panel piensa en «de tres a cinco de la madrugada», no en un
 * instante. No es un momento —no hay nada que convertir ni que almacenar en
 * `TIMESTAMPTZ` (regla dura 3)—: es una franja del reloj de pared, que se compara
 * con el reloj de pared de la tablet. Por eso son dos cadenas `HH:MM` y no dos
 * `DateTimeImmutable`.
 *
 * ## Que significa cada forma
 *
 * | Valor | Que significa |
 * |---|---|
 * | `03:00-05:00` | De las tres a las cinco. Intervalo semiabierto: `05:00` ya esta fuera. |
 * | `23:00-02:00` | Cruza la medianoche. Legitimo: un hotel con turno de noche tiene su hueco tranquilo ahi. |
 * | `03:00-03:00` | **Ventana vacia: no se actualiza nunca sola.** Es un valor legitimo y no un error. |
 *
 * La ultima fila es una decision y no un descuido. Con los dos extremos iguales
 * caben dos lecturas —«nunca» y «siempre»— y la unica que no puede hacer daño es
 * la primera: una instalacion que quiere decidir a mano cuando se actualizan sus
 * tablets lo declara asi, y la alternativa —rechazar el valor— habria obligado al
 * adaptador a lanzar en el camino de fichaje, que es justo lo que la regla dura
 * 19 prohibe.
 */
final readonly class KioskUpdateWindow
{
    /**
     * `HH:MM-HH:MM` en 24 horas.
     *
     * Vive aqui y la copia el catalogo de ajustes: la misma forma valida el borde
     * —para que un valor mal escrito de un `422` con una persona delante— y este
     * objeto, que es quien la garantiza de verdad.
     */
    public const string SHAPE = '/^([01][0-9]|2[0-3]):[0-5][0-9]-([01][0-9]|2[0-3]):[0-5][0-9]$/';

    private function __construct(
        /** Inicio de la franja, `HH:MM` en hora local del centro. Incluido. */
        public string $start,
        /** Fin de la franja, `HH:MM` en hora local del centro. **Excluido**. */
        public string $end,
    ) {}

    /**
     * @param  string  $range  `HH:MM-HH:MM`, tal como lo guarda `KIOSK_UPDATE_WINDOW`
     *
     * @throws InvalidArgumentException si la forma no es la de {@see self::SHAPE}
     */
    public static function fromRange(string $range): self
    {
        if (preg_match(self::SHAPE, $range) !== 1) {
            throw new InvalidArgumentException(
                'La ventana de actualizacion del quiosco se declara como HH:MM-HH:MM y no como "'.$range.'".'
            );
        }

        [$start, $end] = explode('-', $range, 2);

        return new self($start, $end);
    }

    /**
     * La franja tal como se guarda y se documenta: `03:00-05:00`.
     *
     * Es la inversa exacta de {@see self::fromRange()}, y existe para que quien
     * tenga el objeto pueda volver al valor del ajuste sin recomponerlo a mano
     * —el paquete de diagnostico y el log de una instalacion que no se actualiza
     * lo necesitan escrito igual que en el panel—.
     */
    public function toRange(): string
    {
        return $this->start.'-'.$this->end;
    }
}
