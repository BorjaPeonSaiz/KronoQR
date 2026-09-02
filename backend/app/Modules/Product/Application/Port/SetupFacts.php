<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

/**
 * Los hechos de la instalacion que el asistente **deduce en lugar de marcar**
 * (RF-PD-03).
 *
 * ## Por que un puerto y no una consulta suelta
 *
 * Porque las cifras salen de tablas de otros modulos —`users`, `employees`,
 * `departments`, `devices`, `credentials`— y `Product` no puede importar
 * `Identity` ni `Workforce` (doc 02 §1.6). El adaptador consulta el **esquema**,
 * que es compartido, sin nombrar una sola clase de esos modulos: es exactamente
 * el criterio con el que ya vive `DatabasePlanUsageCounter`.
 *
 * La alternativa —pedirle la cifra a cada modulo por su propio puerto— obligaria
 * a `Identity` a exponer «¿cuantas tarjetas faltan?» y a `Workforce` a exponer
 * «¿cuantas altas hay?» para una sola pantalla que se ve una vez en la vida de
 * la instalacion.
 *
 * ## Ninguno de estos metodos devuelve una persona
 *
 * Booleanos y enteros. El resumen del asistente no nombra a nadie (regla dura
 * 21); quien necesite el detalle va a `GET /api/v1/credentials/status`, que
 * exige otro ambito y deja constancia de la consulta.
 */
interface SetupFacts
{
    /**
     * ¿Hay ya una cuenta de gestion **con su segundo factor confirmado**?
     *
     * Las dos mitades, y las dos hacen falta. Una cuenta de `admin` sin TOTP no
     * puede entrar al panel (RS-06): darla por buena dejaria el asistente
     * diciendo que el paso esta hecho mientras nadie puede acceder, que es el
     * callejon sin salida que RF-PD-03 prohibe por escrito.
     */
    public function hasAdministratorWithSecondFactor(): bool;

    /** Altas de plantilla activas. */
    public function activeEmployees(): int;

    public function departments(): int;

    /**
     * Personas activas **sin tarjeta emitida, impresa y entregada**.
     *
     * Es la cifra del resumen final: sin tarjeta no se ficha (ADR-014), y quien
     * importa cuarenta personas y no emite nada tiene cuarenta personas que no
     * pueden fichar el primer dia.
     */
    public function employeesWithoutUsableCredential(): int;

    /** Quioscos vinculados y activos. */
    public function activeKiosks(): int;
}
