<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Lo que una sonda de `product:doctor` encuentra, **antes de traducirlo**
 * (RF-PD-13).
 *
 * ## Por que el hallazgo y el texto son dos objetos
 *
 * La ficha 5.9 exige el informe «en español e ingles, redactado para quien no
 * conoce el sistema». Si la sonda compusiera la frase, el idioma quedaria
 * decidido en el sitio que mide el espacio en disco, y `--lang=en` obligaria a
 * duplicar cada sonda. Aqui la sonda dice **que ha encontrado** —una clave y sus
 * cifras— y {@see DoctorCheck} lleva ya la frase resuelta en el idioma pedido.
 *
 * Es ademas lo que mantiene el dominio puro: traducir es una llamada al
 * traductor del framework, y en `Domain/` no entra ninguna (regla dura 1).
 *
 * ## `details` no lleva secretos ni datos personales
 *
 * Va tal cual al paquete de diagnostico, que sale de la instalacion del cliente
 * (ADR-020, regla dura 21). Cifras, rutas y nombres de servicio; nunca una
 * contraseña, una cadena de conexion, un nombre de empleado ni el de un quiosco.
 */
final readonly class DoctorFinding
{
    /**
     * @param  string  $id  Identificador estable `familia.comprobacion`.
     * @param  array<string, string|int|float|bool|null>  $params  Sustituciones del texto traducido.
     * @param  array<string, mixed>  $details  Cifras y rutas que apoyan el veredicto.
     * @param  string|null  $variant  Sufijo del mensaje cuando una misma comprobacion tiene
     *                                varias explicaciones para el mismo estado — «no se puede leer» y «no
     *                                existe» son dos `failure` distintos de un mismo fichero, y merecen dos
     *                                frases. Nulo significa «el texto por defecto de este estado».
     */
    public function __construct(
        public string $id,
        public DoctorStatus $status,
        public array $params = [],
        public array $details = [],
        public ?string $variant = null,
    ) {}

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string|int|float|bool|null>  $params
     */
    public static function ok(string $id, array $details = [], array $params = []): self
    {
        return new self($id, DoctorStatus::Ok, $params, $details);
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $params
     * @param  array<string, mixed>  $details
     */
    public static function warning(string $id, ?string $variant = null, array $params = [], array $details = []): self
    {
        return new self($id, DoctorStatus::Warning, $params, $details, $variant);
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $params
     * @param  array<string, mixed>  $details
     */
    public static function failure(string $id, ?string $variant = null, array $params = [], array $details = []): self
    {
        return new self($id, DoctorStatus::Failure, $params, $details, $variant);
    }

    /**
     * Clave del texto: `doctor.checks.<id>.<estado>[_<variante>]`.
     *
     * El estado entra en la clave a proposito. La misma comprobacion dice cosas
     * distintas segun como salga —«hay 12 GiB libres» frente a «quedan 400 MiB,
     * el sistema se va a parar»— y una sola frase parametrizada para los tres
     * casos acabaria siendo la frase de ninguno.
     *
     * **La variante va con guion bajo y no con punto**, y no es cosmetica: el
     * traductor de Laravel resuelve el punto como nivel de un array, asi que
     * `warning` y `warning.unknown` no pueden coexistir —el primero seria un
     * array y no una frase—. Con `warning` y `warning_unknown`, si.
     */
    public function messageKey(): string
    {
        return 'doctor.checks.'.$this->id.'.'.$this->status->value.$this->variantSuffix();
    }

    /**
     * Clave del **que hacer**: `doctor.fixes.<id>.<estado>[_<variante>]`.
     *
     * Nula cuando la comprobacion sale bien: el contrato exige `fix` no nulo en
     * todo lo que no sea `ok`, y prohibe inventarse consejos para lo que no
     * tiene ningun problema.
     */
    public function fixKey(): ?string
    {
        return $this->status === DoctorStatus::Ok
            ? null
            : 'doctor.fixes.'.$this->id.'.'.$this->status->value.$this->variantSuffix();
    }

    private function variantSuffix(): string
    {
        return $this->variant === null ? '' : '_'.$this->variant;
    }
}
