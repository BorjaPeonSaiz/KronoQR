<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * El hash del token de las tarjetas activas, para el padron cacheable del
 * quiosco (RF-KI-03, doc 02 §7.3).
 *
 * **Es el hash, nunca el token.** El token en claro existe el tiempo de imprimir
 * la tarjeta y no se almacena en ningun sitio (ADR-034, §5.2): lo que hay en
 * `credentials.secret_hash` es `SHA-256` de 128 bits aleatorios. Con el no se
 * puede fabricar una credencial —invertirlo es inviable y ademas el payload va
 * firmado con una clave que no sale del servidor—, y es lo unico que el quiosco
 * necesita para responder «¿de quien es esta tarjeta?» sin red.
 *
 * **Por que en `Shared/Application/Port`.** Mismo motivo que
 * {@see ClockingEmployees}: quien lo necesita (`Kiosk`) y quien lo tiene
 * (`Identity`) son dos satelites y ninguno puede importar al otro (§1.6). El
 * adaptador vive en `Identity/Infrastructure/Adapter/` y se enlaza en
 * `IdentityServiceProvider` (ADR-025, restriccion 3).
 *
 * **No filtra por centro y no puede hacerlo**: `credentials` no sabe de centros,
 * solo de empleados. El filtro por centro lo aplica quien llama, preguntando
 * antes a `ClockingEmployees`. Son dos consultas y no una union entre tablas de
 * dos modulos, que es la frontera que este puerto existe para respetar.
 */
interface CredentialFingerprints
{
    /**
     * Hash del token de la credencial **activa y ya impresa** de cada empleado
     * indicado, indexado por su clave interna.
     *
     * Quien no tenga tarjeta activa —o la tenga emitida pero sin imprimir, que
     * desde ADR-034 significa sin secreto todavia— simplemente no aparece en el
     * resultado. No es un error: es alguien que hoy no puede fichar con tarjeta y
     * a quien el quiosco no podra resolver, que es la verdad.
     *
     * @param  list<int>  $employeeIds  Claves internas. Con la lista vacia, el resultado
     *                                  es vacio y no se consulta nada.
     * @return array<int, string> `employee_id` => hash en hexadecimal.
     */
    public function forEmployees(array $employeeIds): array;
}
