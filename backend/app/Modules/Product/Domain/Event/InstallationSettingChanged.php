<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Una clave de la configuracion de la instalacion ha cambiado de valor
 * (**RF-PD-01**, RL-04, regla dura 6).
 *
 * ## Por que este hecho se audita
 *
 * Doc 01 §5, nota de `installation_settings`: *«todo cambio queda auditado,
 * porque algunos afectan al calculo de horas»*. La ventana anti-rebote de
 * RF-AT-06 es el caso claro: un escaneo que la ventana se traga no cierra el
 * tramo, y el total de la jornada sale distinto sin que nadie haya tocado un
 * fichaje. Es exactamente la familia «cambia roles, permisos o parametros del
 * calculo» del bloque D de `/revision-cumplimiento`.
 *
 * ## Uno por clave, no uno por peticion
 *
 * Un `PATCH` puede cambiar tres claves y produce tres eventos, y por tanto tres
 * asientos. Es deliberado: cada asiento habla de **una** clave, con su antes, su
 * despues y su `affects_worked_hours`. Un asiento por peticion obligaria a
 * decidir un unico `affects_worked_hours` para un conjunto mixto —marca e
 * umbral cambiados a la vez— y ese booleano perderia justo el matiz para el que
 * existe; ademas, «¿quien cambio el anti-rebote y cuando?» se contestaria
 * leyendo el JSON de asientos que hablan tambien de otras cosas.
 *
 * ## Que lleva
 *
 * El antes y el despues, y si la clave afecta al calculo de horas. **Ni un
 * nombre** (regla dura 21): quien lo hizo lo resuelve el asiento a partir de la
 * sesion en curso, porque es una propiedad de la peticion y no del hecho, igual
 * que en el resto de los eventos del producto.
 *
 * `wasProductDefault` distingue «se subio de 12 a 10» de «nadie lo habia tocado
 * nunca y ahora vale 10». Son indistinguibles en la cifra anterior —el valor de
 * serie tambien es 12— y muy distintos en la conversacion: en el primer caso
 * hubo una decision previa que alguien tomo, en el segundo esta es la primera.
 */
final readonly class InstallationSettingChanged implements DomainEvent
{
    /**
     * @param  int|string|list<string>  $previousValue  lo que regia antes: la fila anterior, o el valor de serie si no habia fila
     * @param  int|string|list<string>  $newValue  lo que queda escrito
     */
    public function __construct(
        /** La clave del catalogo, tal cual (`ATTENDANCE_DEBOUNCE_SECONDS`). */
        public string $key,
        public int|string|array $previousValue,
        public int|string|array $newValue,
        /** `worked_hours`, `compliance_review` o `presentation`. */
        public string $impact,
        /** El booleano del asiento: si esto pudo cambiar los minutos del registro legal. */
        public bool $affectsWorkedHours,
        /** Si antes no habia fila y regia el valor de serie del producto. */
        public bool $wasProductDefault,
        private DateTimeImmutable $occurredAt,
    ) {}

    /**
     * Nombre estable. No se deriva del nombre de la clase (doc 02 §1.6):
     * renombrar una clase no puede cambiar lo que ya esta escrito en un registro
     * con valor legal.
     */
    public function eventName(): string
    {
        return 'product.installation_setting_changed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
