<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

use App\Modules\Kiosk\Domain\Exception\InvalidPairingCode;

/**
 * El codigo que la tablet enseña y una persona teclea en el panel
 * (**RF-PD-06**, tarea 5.6).
 *
 * ## Seis digitos decimales, y no mas
 *
 * Se lee de lejos —una tablet colgada a dos metros— y se teclea a mano. Lo que
 * protege el emparejamiento **no es la entropia de este numero**: es el secreto
 * de recogida de 32 bytes que no sale de la tablet, la caducidad de diez minutos
 * y el hecho de que nada se vincula sin el `confirm` de un `admin`. Subirlo a
 * diez digitos no añadiria seguridad y si haria que alguien lo tecleara mal.
 *
 * **Solo digitos**, ademas: un alfabeto mixto a dos metros convierte la O en un
 * cero y el 1 en una l, y quien lo teclea no tiene forma de saber cual de las dos
 * era.
 *
 * ## Este objeto NO hashea
 *
 * Valida la forma, y nada mas. El SHA-256 y el
 * `hash_equals` viven en el adaptador `RandomPairingSecrets`, que es
 * infraestructura: el dominio no sabe con que algoritmo se guarda un secreto ni
 * tiene por que saberlo, y meter aqui una funcion de hash haria imposible probar
 * el agregado sin decidir tambien como se persiste.
 *
 * ## Se normaliza el espacio en blanco, y solo eso
 *
 * La tablet lo muestra agrupado —«483 921»— y quien lo teclea lo copia tal cual
 * mas de una vez. Quitar espacios es corregir un artefacto de la presentacion, no
 * ser permisivo: cualquier otro caracter sigue siendo un codigo invalido.
 *
 * **El agrupamiento lo hace el cliente, no esta clase.** Aqui vivio un
 * `forDisplay()` que devolvia «483 921» y no lo llamaba nadie: quien pinta el
 * codigo es la PWA, que ya decide su tipografia y su separacion, y el servidor
 * no tiene por que opinar sobre como se ve. Lo que viaja por la API es el valor
 * sin espacios, que es tambien lo que se hashea.
 */
final readonly class PairingCode
{
    /** Cuantos digitos tiene. Se declara aqui y el contrato lo repite en su `pattern`. */
    public const int LENGTH = 6;

    private function __construct(public string $value) {}

    /**
     * @throws InvalidPairingCode si no son exactamente seis digitos decimales
     */
    public static function of(string $code): self
    {
        $normalized = str_replace([' ', "\t", '-'], '', trim($code));

        if (preg_match('/^[0-9]{'.self::LENGTH.'}$/', $normalized) !== 1) {
            throw new InvalidPairingCode;
        }

        return new self($normalized);
    }

    /**
     * Si una cadena cualquiera tiene forma de codigo.
     *
     * Existe para el borde, que necesita decidir sin lanzar: un `422` de
     * validacion y una excepcion de dominio no son lo mismo para quien llama.
     */
    public static function isWellFormed(string $code): bool
    {
        return preg_match('/^[0-9]{'.self::LENGTH.'}$/', $code) === 1;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
