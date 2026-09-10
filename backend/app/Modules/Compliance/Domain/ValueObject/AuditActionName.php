<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * El nombre de una accion auditable **tal y como esta escrito en la fila**, con
 * el caso del catalogo cerrado detras solo si esta version lo conoce.
 *
 * ## Por que existe: la cadena se verifica por hash, no por catalogo
 *
 * La formula del doc 02 §7.4 mete la accion en el hash **como cadena literal**.
 * El catalogo {@see AuditAction} sirve para *escribir* —para que ningun modulo
 * invente un nombre nuevo por su cuenta— pero no manda nada sobre *leer*: una
 * fila cuya accion no esta en el catalogo de esta version sigue teniendo un hash
 * perfectamente comprobable, porque el hash se recalcula con la cadena.
 *
 * ## El defecto concreto que cierra
 *
 * Una actualizacion que sale mal se deshace, y despues de deshacerla corre la
 * version **anterior** sobre una base en la que la version **siguiente** ya
 * escribio asientos. Eso ocurrio en la etapa ⑧b de la CI del cierre de Fase 5:
 * la 2.1.0 verificaba una cadena que contenia `system.restored_from_backup`
 * —accion que ella no conoce, porque la estreno la version de despues— y
 * `AuditAction::from()` reventaba con un `ValueError`. El verificador diario de
 * RS-07 dejaba de correr por una accion que no era ninguna rotura.
 *
 * La direccion del problema es estructural, no accidental: **las acciones nuevas
 * siempre las escribe la version siguiente**, asi que cualquier verificador
 * antiguo tiene que poder recorrer filas con acciones que no conoce. Un catalogo
 * cerrado en la lectura convierte cada accion nueva en una bomba de relojeria
 * para la version anterior.
 *
 * ## Y aun asi el catalogo sigue cerrado donde importa
 *
 * Una accion desconocida solo puede nacer de {@see self::fromStorage()}, que es
 * el camino de **lectura**. Todo camino de escritura sigue recibiendo un
 * {@see AuditAction} y pasando por {@see self::of()}: no hay forma de escribir
 * en `audit_log` una accion fuera del catalogo, que era la razon de ser del
 * enum. Lo que cambia es que leer ya no exige reconocer.
 *
 * `requiresSystemActor()` y `event()` responden «no lo se» ante un nombre
 * desconocido (`false` y `null`) en lugar de adivinar por el prefijo: en la
 * lectura no hay nada que validar —la fila ya esta escrita y el hash es el
 * juez— y clasificar por su cuenta una accion que esta version no conoce seria
 * inventarse su semantica.
 */
final readonly class AuditActionName
{
    private function __construct(
        /** La cadena literal que entra en el hash del §7.4. Nunca normalizada. */
        public string $value,
        private ?AuditAction $known,
    ) {}

    /**
     * El camino de escritura: siempre desde el catalogo.
     */
    public static function of(AuditAction $action): self
    {
        return new self($action->value, $action);
    }

    /**
     * El camino de lectura: **nunca falla**.
     *
     * `tryFrom()` y no `from()`. Lo que no se reconoce se conserva como cadena,
     * que es exactamente lo que hace falta para recalcular su hash.
     */
    public static function fromStorage(string $value): self
    {
        return new self($value, AuditAction::tryFrom($value));
    }

    /**
     * El caso del catalogo, o `null` si esta version no conoce el nombre.
     *
     * Quien necesite la semantica de la accion —y no solo su nombre— pasa por
     * aqui y decide que hacer con el `null`. Lo que no vale es asumir que
     * siempre hay enum detras.
     */
    public function known(): ?AuditAction
    {
        return $this->known;
    }

    public function isKnown(): bool
    {
        return $this->known !== null;
    }

    /**
     * La familia del bloque D a la que pertenece, o `null` si el nombre es
     * desconocido para esta version.
     */
    public function event(): ?AuditableEvent
    {
        return $this->known?->event();
    }

    /**
     * Invariante de escritura ({@see AuditEntryDraft}). Un nombre desconocido
     * responde `false`: solo llega desde la base de datos, donde la fila ya
     * existe y no hay nada que impedir.
     */
    public function requiresSystemActor(): bool
    {
        return $this->known?->requiresSystemActor() ?? false;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
