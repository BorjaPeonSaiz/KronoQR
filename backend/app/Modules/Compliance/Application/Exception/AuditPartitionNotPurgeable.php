<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Exception;

use RuntimeException;

/**
 * La particion de `audit_log` no se suelta, y el comando aborta (ADR-027, RS-07).
 *
 * **Abortar es la respuesta correcta y no una cautela.** Una particion cuya
 * cadena no verifica es un incidente de integridad abierto: soltarla destruiria
 * la unica prueba de que hubo manipulacion, y sellarla escribiria un ancla
 * afirmando que todo encajaba. Se deja donde esta y se investiga con
 * `docs/runbooks/rotura-cadena-auditoria.md`.
 *
 * **La purga se aborta entera**, tambien lo que aun no se ha tocado: las
 * particiones se sueltan de la mas antigua a la mas nueva y cada una encadena
 * con la siguiente, asi que no hay forma de saltarse una rota y seguir.
 */
final class AuditPartitionNotPurgeable extends RuntimeException
{
    public static function chainIsBroken(int $year, string $detail): self
    {
        return new self(
            'La cadena de la particion audit_log_'.$year.' NO verifica ('.$detail.'). '
            .'No se sella ni se suelta: es un incidente de integridad (RS-07). '
            .'Procedimiento: docs/runbooks/rotura-cadena-auditoria.md'
        );
    }

    public static function isNotAtTheHeadOfTheChain(int $year): self
    {
        return new self(
            'La particion audit_log_'.$year.' no queda delante de toda la cadena viva: hay entradas mas '
            .'nuevas escritas antes que alguna de las suyas -una entrada retrodatada, regla dura 9-. '
            .'Soltarla abriria un hueco EN MEDIO de la cadena, que el ancla no explica, y el verificador '
            .'denunciaria rotura para siempre. Se deja donde esta.'
        );
    }
}
