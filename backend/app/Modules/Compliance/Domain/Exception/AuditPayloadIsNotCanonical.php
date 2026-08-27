<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

/**
 * El `payload` no admite una serializacion canonica y determinista, que es la
 * condicion sin la cual la cadena de hash no prueba nada (doc 02 §7.4,
 * `/revision-cumplimiento` bloque C).
 *
 * Se lanza en dos casos, y los dos son un defecto de quien construye la entrada,
 * no una circunstancia del sistema:
 *
 * - **Un valor que no es escalar, `null` ni lista/mapa de los anteriores.** Un
 *   objeto se serializa segun como este implementado hoy; si mañana gana una
 *   propiedad, el mismo hecho produce otro hash y el verificador denuncia una
 *   rotura que no existe.
 * - **Un flotante no finito** (`NAN`, `INF`). No tienen representacion en JSON.
 */
final class AuditPayloadIsNotCanonical extends ComplianceDomainException
{
    public static function unsupportedValue(string $path, string $type): self
    {
        return new self(sprintf(
            'El payload de auditoria lleva un valor de tipo «%s» en «%s». '
            .'Solo admite null, bool, int, float finito, string y arrays de esos tipos: '
            .'cualquier otra cosa hace que el mismo hecho pueda producir dos hashes distintos.',
            $type,
            $path,
        ));
    }

    public static function nonFiniteFloat(string $path): self
    {
        return new self(sprintf(
            'El payload de auditoria lleva un flotante no finito en «%s». JSON no lo representa.',
            $path,
        ));
    }

    public static function invalidUtf8(string $path): self
    {
        return new self(sprintf(
            'El payload de auditoria lleva una cadena que no es UTF-8 valido en «%s». '
            .'La serializacion canonica es UTF-8 (doc 02 §7.4).',
            $path,
        ));
    }
}
