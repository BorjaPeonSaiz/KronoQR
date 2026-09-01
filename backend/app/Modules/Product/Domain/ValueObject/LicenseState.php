<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureRestriction;

/**
 * En que situacion esta la licencia de esta instalacion (RF-PD-04).
 *
 * ## Seis estados y ninguno bloquea nada
 *
 * Es lo primero que hay que decir de este enum: **ninguno de estos valores
 * detiene el fichaje ni el acceso al registro** (regla dura 15, ADR-019). Lo
 * unico que gobiernan es que funcionalidades **accesorias** —las de
 * {@see Feature}— estan disponibles, y
 * que aviso se enseña en el panel.
 *
 * Son seis y no dos porque la accion siguiente del cliente es distinta en cada
 * uno, y una degradacion honesta tiene que decir **que hacer** (ADR-019). Un
 * unico «licencia no valida» obliga a llamar por telefono para saber si hay que
 * renovar, activar, esperar o pedir una clave nueva.
 *
 * ## `ExpiringSoon` no degrada nada
 *
 * Es un estado de aviso, no de recorte: la licencia sigue siendo valida a todos
 * los efectos. Existe para que el banner del panel aparezca **antes** de que el
 * cliente se quede sin sus informes, con los dias de antelacion que fija
 * `config/license.php` (30 de serie). Un aviso que llega el dia de la caducidad
 * no es un aviso, es una notificacion de averia.
 */
enum LicenseState: string
{
    /** No hay ninguna clave activada. Una instalacion recien puesta en marcha esta aqui. */
    case Absent = 'absent';

    /** Hay clave guardada y no verifica: formato, firma, otro emisor, o esta compilacion no lleva clave publica. */
    case Unverifiable = 'unverifiable';

    /** Verifica, pero su vigencia empieza mas adelante. Ocurre al activar la renovacion con antelacion. */
    case NotYetValid = 'not_yet_valid';

    /** Verifica y esta vigente. */
    case Valid = 'valid';

    /** Verifica, esta vigente y caduca dentro de la ventana de aviso. Todo sigue habilitado. */
    case ExpiringSoon = 'expiring_soon';

    /** Verifica y su vigencia termino. Es el caso que gobierna ADR-019. */
    case Expired = 'expired';

    /**
     * Si en este estado las funcionalidades accesorias contratadas estan
     * disponibles.
     *
     * **Solo dos estados conceden**, y `ExpiringSoon` es uno de ellos: una
     * licencia a la que le quedan veintinueve dias esta vigente y recortarle
     * nada seria adelantar la caducidad un mes.
     */
    public function grantsFeatures(): bool
    {
        return $this === self::Valid || $this === self::ExpiringSoon;
    }

    /**
     * El motivo con el que se explica la degradacion, o `null` si no hay
     * degradacion que explicar.
     *
     * Traduce el estado de la **licencia** al vocabulario de la
     * **funcionalidad**, que es el que entiende quien tiene que redactar el
     * aviso. `NotInPlan` no sale de aqui: ese motivo no depende del estado sino
     * de que la funcionalidad no este en `features`, y lo decide
     * {@see LicenseStatus}.
     */
    public function restriction(): ?FeatureRestriction
    {
        return match ($this) {
            self::Valid, self::ExpiringSoon => null,
            self::Absent => FeatureRestriction::LicenseAbsent,
            self::Unverifiable => FeatureRestriction::LicenseUnverifiable,
            self::NotYetValid => FeatureRestriction::LicenseNotYetValid,
            self::Expired => FeatureRestriction::LicenseExpired,
        };
    }

    /**
     * Si el panel tiene que enseñar el banner persistente de licencia.
     *
     * Todos menos el estado normal. `ExpiringSoon` entra —es la razon de que ese
     * estado exista— y `Valid` no: un banner permanente en una instalacion sana
     * se aprende a ignorar, y entonces tampoco se lee el dia que dice algo.
     */
    public function needsNotice(): bool
    {
        return $this !== self::Valid;
    }

    /**
     * La gravedad con la que se pinta el aviso, para que el panel no tenga que
     * repetir este `match` (y para que las tres superficies —panel, API y
     * consola— coincidan).
     */
    public function severity(): LicenseNoticeSeverity
    {
        return match ($this) {
            self::Valid => LicenseNoticeSeverity::None,
            self::ExpiringSoon, self::NotYetValid => LicenseNoticeSeverity::Warning,
            self::Absent, self::Unverifiable, self::Expired => LicenseNoticeSeverity::Critical,
        };
    }
}
