<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * Deja constancia de que alguien ha accedido a datos personales de terceros
 * (**RS-05**, regla dura 6).
 *
 * ## Por que existe un puerto solo para esto
 *
 * RS-05 no admite matices: *«todo acceso a datos personales de terceros queda
 * registrado en el trail de auditoria»*. El primer caso del producto es el padron
 * del quiosco —un token de dispositivo se lleva los nombres de una plantilla
 * entera—, y `Kiosk` no puede importar `Compliance`: el §1.6 no concede esa
 * arista y Deptrac la verifica.
 *
 * De las tres vias de comunicacion entre modulos, aqui no sirve el evento de
 * dominio —no ha pasado nada en el dominio de `Kiosk`, ha pasado que alguien
 * **leyo**— ni la llamada al caso de uso publico de `Compliance`, que sigue sin
 * ser alcanzable. Queda la tercera, que es la de ADR-025: el consumidor declara el
 * puerto y el que tiene la maquinaria lo implementa. El adaptador vive en
 * `Compliance/Infrastructure/Adapter/`.
 *
 * ## Deliberadamente estrecho
 *
 * **No es un escritor de auditoria de proposito general.** No acepta accion, ni
 * actor, ni sujeto: solo describe una divulgacion. Si aceptara una `AuditAction`
 * cualquiera, cualquier modulo podria escribir en `audit_log` sin pasar por el
 * diseño de eventos, y el catalogo cerrado de acciones dejaria de estar cerrado.
 * La accion es siempre `personal_data.accessed` y la decide el adaptador.
 *
 * El **actor** tampoco viaja como parametro: lo resuelve el adaptador de la
 * peticion en curso, igual que para el resto de la auditoria. Quien accede no
 * puede declarar quien es.
 *
 * ## Nunca los datos, solo la forma del acceso
 *
 * El `context` describe **el alcance** —que centro, cuantos registros, para que
 * dispositivo— y jamas lo divulgado (regla dura 21): ni un nombre, ni un hash de
 * tarjeta. Un `audit_log` que copiara lo que se leyo seria una segunda copia del
 * padron con cuatro años de retencion.
 *
 * ## La excepcion: cuando los datos SALEN de la instalacion
 *
 * Enumerar los `employee_uuid` de los afectados esta descartado para los
 * conjuntos grandes que se leen **dentro** del servidor —el padron, el
 * directorio, la bandeja—: son cientos de personas por lectura y el trail
 * acabaria siendo el propio padron. Cuando el conjunto es pequeño y **sale de la
 * instalacion** —hoy solo `incident_digest`, el resumen que se envia por correo
 * al responsable (RF-PR-01)— la lista si va, porque sin ella el asiento no
 * responde a la pregunta que RL-15 obliga a contestar: de quien se fueron los
 * datos. Siguen siendo identificadores, nunca nombres.
 */
interface PersonalDataAccessLog
{
    /**
     * @param  string  $dataset  Que se ha divulgado, en vocabulario estable y en ingles
     *                           (`kiosk_roster`). Es lo que permite consultar despues
     *                           «cuantas veces se descargo el padron».
     * @param  int  $recordCount  Cuantos registros llevaba la respuesta. Es el dato que
     *                            convierte «alguien miro» en «alguien se llevo la
     *                            plantilla entera».
     * @param  array<string, scalar>  $context  Alcance del acceso. Sin datos personales.
     */
    public function recordDisclosure(string $dataset, int $recordCount, array $context = []): void;
}
