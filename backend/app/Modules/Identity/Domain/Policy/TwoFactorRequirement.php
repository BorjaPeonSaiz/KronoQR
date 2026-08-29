<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Policy;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien esta obligado a llevar segundo factor y que le pasa si todavia no lo
 * tiene (**RS-06**, RF-ID-01).
 *
 * ## La regla, en una frase
 *
 * *«2FA obligatorio para `admin`, `rrhh` y `auditor`»* (RS-06). Son los roles con
 * acceso a datos de **toda** la plantilla; el `responsable_departamento` no entra
 * porque su alcance esta acotado a su departamento (RF-ID-03).
 *
 * ## Por que la lista entra por el constructor y no esta escrita aqui
 *
 * Regla dura 13 y 14: los umbrales y las politicas son configuracion, no
 * constantes. Un cliente con una politica de seguridad mas dura exige segundo
 * factor tambien a sus responsables sin tocar el repositorio y sin una rama
 * propia. Los valores de serie son los tres de RS-06 y viven en
 * `config/identity.php`; **esta clase no consulta la configuracion**, la recibe
 * resuelta, igual que el dominio recibe los umbrales legales.
 *
 * ## Contradiccion documental que esta clase resuelve
 *
 * El doc 01 (RS-06) obliga a `admin`, `rrhh` y `auditor`. La tabla del doc 02 §7.3
 * escribe «Sesion + 2FA» tambien en la fila del responsable. Manda el doc 01
 * (orden de autoridad de `CLAUDE.md`), asi que el valor de serie son tres roles
 * — y como la lista es configuracion, la lectura del doc 02 sigue siendo
 * alcanzable en una instalacion que la quiera.
 *
 * ## Quien ya lo tiene, lo usa
 *
 * Una cuenta que activo su TOTP recibe el reto **aunque su rol no lo exija**. Lo
 * contrario —ignorar el segundo factor de un responsable porque no es
 * obligatorio— convertiria una proteccion que alguien eligio tener en un adorno,
 * y dejaria su cuenta a la altura de su contrasena sin avisarle.
 */
final readonly class TwoFactorRequirement
{
    /**
     * @param  list<UserRole>  $mandatoryFor  Roles que no pueden entrar sin segundo factor.
     */
    public function __construct(private array $mandatoryFor) {}

    /**
     * @param  list<UserRole>  $roles  Roles de la cuenta.
     */
    public function isMandatoryFor(array $roles): bool
    {
        foreach ($roles as $role) {
            if (\in_array($role, $this->mandatoryFor, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si el acceso tiene que detenerse en un reto de segundo factor.
     *
     * @param  list<UserRole>  $roles
     * @param  bool  $secondFactorActive  Si la cuenta tiene un TOTP ya confirmado.
     */
    public function challenges(array $roles, bool $secondFactorActive): bool
    {
        return $secondFactorActive || $this->isMandatoryFor($roles);
    }

    /**
     * Si ademas del reto hay que **dar de alta** el segundo factor antes de poder
     * entrar: rol obligado y sin TOTP confirmado todavia.
     *
     * @param  list<UserRole>  $roles
     */
    public function enrolmentRequired(array $roles, bool $secondFactorActive): bool
    {
        return ! $secondFactorActive && $this->isMandatoryFor($roles);
    }
}
