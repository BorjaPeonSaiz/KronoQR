<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

/**
 * Publica el estado de la licencia donde la **sonda de vida** pueda leerlo sin
 * tocar dependencias (doc 02 §10.5, paso 9 de la tarea 5.3).
 *
 * ## Por que existe este puerto
 *
 * `GET /api/v1/health` no puede consultar la base de datos: una sonda de vida
 * que lo hiciera haria que Docker reiniciara el contenedor de PHP cuando lo
 * caido es PostgreSQL (tarea 1.7). Asi que el estado se publica **cuando ya se
 * ha calculado por otro motivo** —una pantalla del panel, `license:show`, una
 * activacion— y la sonda lee esa copia.
 *
 * Lo llama `GetLicenseStatusHandler`,
 * que es el unico punto de resolucion: asi la copia se refresca por **cualquier**
 * camino y no solo por el del `FeatureGate`. Que quede desfasada es aceptable y
 * esta documentado; que quede desfasada justo despues de activar una clave, no.
 *
 * ## Se publica una palabra
 *
 * El estado y nada mas. Ni el cliente, ni el plan, ni las fechas: la sonda es
 * publica y sin autenticacion, y eso es informacion comercial del cliente
 * (ADR-020, regla dura 21).
 *
 * ## No puede fallar hacia arriba
 *
 * Si el almacen no responde, la sonda dira `unknown`, que es la verdad. Publicar
 * es un efecto secundario de una lectura y no puede romper la peticion que iba a
 * servirse.
 */
interface LicenseStatePublisher
{
    public function publish(string $state): void;
}
