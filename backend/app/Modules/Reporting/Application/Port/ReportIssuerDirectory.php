<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * El **nombre de la cuenta** que emitio un documento, para poder sellarlo
 * (**RF-IN-04**).
 *
 * ## Por que hace falta un nombre aqui, si la regla dura 21 los prohibe
 *
 * La regla dura 21 prohibe nombres en **logs tecnicos** y en `error_events`,
 * porque aquello viaja al fabricante dentro del paquete de diagnostico. Esto es
 * lo contrario: es el pie de un documento que se imprime y se archiva, y un
 * informe de horas sin emisor no dice quien responde de el. El asiento de
 * `audit_log` sigue llevando el `uuid` y no el nombre; el papel lleva el nombre
 * y no el `uuid`, porque quien lo lee es una persona.
 *
 * **El correo no**, y es deliberado: identifica una cuenta fuera del sistema y no
 * aporta nada a quien lee un pie de pagina. Ademas el producto no depende del
 * correo de nadie (regla dura 12).
 *
 * ## Por que no se le pregunta a `ManagementActor`
 *
 * Aquel puerto responde tres cosas —quien es, que rol tiene y hasta donde
 * alcanza— y su contrato dice por escrito que son las tres que necesita una
 * policy y ni una mas. El nombre no es una pregunta de autorizacion: ampliarlo
 * obligaria a todas las policies del producto a conocer un dato que ninguna usa.
 *
 * ## Devuelve `null` y el documento sigue saliendo
 *
 * Una cuenta que se borro entre la peticion y el sello es un caso imposible hoy
 * —nada se borra, regla dura 5— pero el puerto no lo presupone. Sin nombre, el
 * pie escribe el rotulo de «cuenta desconocida» y el informe se entrega igual:
 * quedarse sin descarga por no poder rotular el pie seria desproporcionado, y el
 * `uuid` del emisor esta en `audit_log` de todas formas.
 */
interface ReportIssuerDirectory
{
    /**
     * @param  string  $actorUuid  `users.uuid` de la cuenta autenticada.
     * @return string|null Nombre para mostrar, o `null` si no se puede resolver.
     */
    public function displayNameOf(string $actorUuid): ?string;
}
