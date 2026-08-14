<?php

declare(strict_types=1);

/*
 * API del producto, version 1.
 *
 * Todas las rutas de este fichero cuelgan de /api/v1: la version va en la
 * ruta, no en una cabecera (ADR-012), y el prefijo se declara una sola vez, en
 * bootstrap/app.php.
 *
 * Este fichero esta vacio a proposito. El contrato docs/api/openapi.yaml es la
 * fuente de verdad de la API y se modifica ANTES que el codigo (ADR-013), asi
 * que ningun endpoint entra aqui antes de estar descrito alli:
 *
 *   GET  /api/v1/health   sonda de salud (doc 01 Anexo B)  -> tareas 0.6 y 1.7
 *   GET  /api/v1/ready    sonda de arranque                -> tareas 0.6 y 1.7
 *   POST /api/v1/scan     registro de escaneo del quiosco  -> tareas 0.6 y 1.7
 *
 * Cada endpoint llega con su policy y su prueba de autorizacion negativa por
 * rol (regla dura 18), sin excepciones.
 */
