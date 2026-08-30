<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use App\Modules\Identity\Domain\Exception\InvalidSigningKey;

/**
 * Las claves de firma vigentes: la actual y, mientras dure una rotacion, la
 * anterior (doc 02 §5.3, RF-QR-07).
 *
 * **Por que existe el solape.** Rotar la clave sin `key_id` obligaria a
 * reimprimir toda la plantilla **en un solo dia**: en cuanto la clave cambia,
 * ninguna tarjeta antigua verifica. Con dos claves activas, la nueva firma lo
 * que se emite desde hoy, la anterior sigue admitiendo lo que ya esta impreso, y
 * la reimpresion se reparte en semanas sin dejar a nadie sin fichar. La clave
 * anterior se retira cuando el panel confirma que no queda ninguna credencial
 * activa con ese `key_id` (RF-QR-08, `credentials:status --key-id=`).
 *
 * **Solo dos claves, y a proposito.** Una tercera significaria que una rotacion
 * anterior nunca se cerro; el §5.3 habla de `current` y `previous` y nada mas.
 * El flujo completo de rotacion es la tarea 2.12: aqui esta el soporte de
 * verificacion con dos claves, que es lo que la Fase 1 necesita.
 *
 * **Un llavero puede estar vacio.** Una instalacion recien desplegada, antes de
 * que el instalador genere los secretos, no tiene ninguna clave. No es motivo
 * para que el fichaje reviente: {@see find()} devuelve `null`, el verificador
 * rechaza de forma generica y deja constancia en el log. Lo que si falla, y con
 * ruido, es intentar **emitir** (ver {@see current()}).
 */
final readonly class QrKeyring
{
    /**
     * @param  array<string, QrSigningKey>  $keys  Indexadas por `key_id`.
     */
    private function __construct(
        private array $keys,
        private ?QrSigningKey $current,
    ) {}

    public static function of(?QrSigningKey $current, ?QrSigningKey $previous = null): self
    {
        $keys = [];

        // La actual se indexa la ULTIMA: si por un error de configuracion las
        // dos variables llevaran el mismo `key_id`, manda la que firma hoy.
        if ($previous instanceof QrSigningKey) {
            $keys[$previous->id] = $previous;
        }

        if ($current instanceof QrSigningKey) {
            $keys[$current->id] = $current;
        }

        return new self($keys, $current);
    }

    public static function empty(): self
    {
        return new self([], null);
    }

    /**
     * **Paso 2 de la verificacion del §5.2**: resolver la clave por su `key_id`.
     *
     * Una clave desconocida —o una ya retirada— devuelve `null`, y quien llama
     * lo trata como cualquier otro rechazo: mismo mensaje y mismo tiempo
     * (RS-03).
     */
    public function find(string $keyId): ?QrSigningKey
    {
        return $this->keys[$keyId] ?? null;
    }

    /**
     * La clave con la que se firma lo que se emite hoy.
     *
     * @throws InvalidSigningKey si la instalacion no tiene clave configurada
     */
    public function current(): QrSigningKey
    {
        if (! $this->current instanceof QrSigningKey) {
            // Emitir sin clave produciria tarjetas que nadie puede verificar.
            // Mejor no emitir.
            throw InvalidSigningKey::noneConfigured();
        }

        return $this->current;
    }

    public function hasCurrent(): bool
    {
        return $this->current instanceof QrSigningKey;
    }

    /**
     * La clave **saliente** de un solape: la que verifica pero ya no firma nada.
     *
     * Es `null` fuera de una rotacion, que es el estado normal. Devuelve solo el
     * identificador y nunca la clave: quien pregunta —el comando de rotacion, el
     * panel de estado, la metrica de avance— necesita saber **cual** queda por
     * reimprimir, no con que se firmo.
     *
     * Si las dos variables llevaran el mismo `key_id` por un error de
     * configuracion, no hay solape que declarar y esto devuelve `null`: el
     * llavero las habra colapsado en una sola entrada (ver {@see of()}).
     */
    public function previousId(): ?string
    {
        $currentId = $this->current?->id;

        foreach (array_keys($this->keys) as $keyId) {
            if ($keyId !== $currentId) {
                return $keyId;
            }
        }

        return null;
    }

    /**
     * Identificadores de las claves vigentes, para el panel de estado y para el
     * diagnostico. **Nunca el material de clave.**
     *
     * @return list<string>
     */
    public function keyIds(): array
    {
        return array_keys($this->keys);
    }
}
