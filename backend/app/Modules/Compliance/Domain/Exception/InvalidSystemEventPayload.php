<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

/**
 * El payload de un asiento `system.*` no cumple la lista cerrada de su accion
 * (RF-PD-10, reglas duras 6 y 21, tarea 5.7).
 *
 * **Falla cerrado.** Quien construye este payload es un script de shell, no un
 * caso de uso con tipos: si se le cuela una clave que nadie espera, un correo
 * dentro de un nombre de fichero o una ruta absoluta del servidor, la unica
 * defensa es que el dominio lo rechace **antes** de que entre en una tabla que
 * no admite `UPDATE` ni `DELETE`. Un asiento con datos personales dentro no se
 * puede corregir: solo se puede explicar.
 */
final class InvalidSystemEventPayload extends ComplianceDomainException
{
    public static function notASystemAction(string $action): self
    {
        return new self(sprintf(
            'La accion «%s» no es del ciclo de vida de la instalacion: este payload solo describe '
            .'acciones «system.*».',
            $action,
        ));
    }

    public static function missingField(string $action, string $field): self
    {
        return new self(sprintf(
            'Falta el campo obligatorio «%s» del payload de «%s».',
            $field,
            $action,
        ));
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function unknownField(string $action, string $field, array $allowed): self
    {
        return new self(sprintf(
            'El campo «%s» no esta en la lista cerrada del payload de «%s». Admitidos: %s. '
            .'Anadir uno es una decision del catalogo, no del script que lo escribe.',
            $field,
            $action,
            implode(', ', $allowed),
        ));
    }

    public static function malformedField(string $field, string $expected): self
    {
        return new self(sprintf(
            'El campo «%s» del payload no cumple su forma: se esperaba %s.',
            $field,
            $expected,
        ));
    }

    public static function looksLikePersonalData(string $field, string $kind): self
    {
        return new self(sprintf(
            'El campo «%s» del payload parece contener %s. Un asiento de auditoria no lleva datos '
            .'personales (regla dura 21) y esta tabla no se puede corregir despues.',
            $field,
            $kind,
        ));
    }
}
