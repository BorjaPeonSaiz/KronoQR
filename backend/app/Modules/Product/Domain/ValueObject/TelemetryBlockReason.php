<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Por que NO se ha enviado nada (**RF-PD-12**, ADR-023, ficha 5.10 punto 8).
 *
 * ## Las tres condiciones, y hacen falta las tres
 *
 * `TELEMETRY_ENABLED=true`, `TELEMETRY_ENDPOINT` con destino y `telemetry` en
 * las funcionalidades de la licencia. Con cualquiera de las tres sin cumplir no
 * se construye ni se envia nada — ni siquiera se lee un contador—.
 *
 * ## Por que el motivo es un enum y no un texto
 *
 * Porque `product:telemetry` tiene que **decir cual falta**, y decirlo igual en
 * los dos idiomas. Un cliente que activo la variable y no ve envios necesita
 * saber si le falta el destino o si su plan no incluye la telemetria; sin esto,
 * la respuesta seria «no se envia» y una llamada a soporte.
 *
 * El orden en el que se comprueban tambien importa y es este: primero la
 * voluntad del cliente, despues su configuracion, y solo al final el plan. Decir
 * «no esta en tu plan» a alguien que ni siquiera la ha activado seria un
 * argumento comercial disfrazado de diagnostico.
 */
enum TelemetryBlockReason: string
{
    /** `TELEMETRY_ENABLED` es `false`, que es el valor de serie del producto. */
    case DisabledByConfiguration = 'disabled_by_configuration';

    /** `TELEMETRY_ENDPOINT` esta vacio: no hay a donde enviar. */
    case EndpointNotConfigured = 'endpoint_not_configured';

    /**
     * `TELEMETRY_ENDPOINT` no empieza por `https://`.
     *
     * **Se trata igual que no tener destino**: no se construye ni se envia
     * nada. Un destino en claro sacaria el documento por la red del hotel sin
     * cifrar y sin autenticar al otro extremo, y ahi cualquiera lo leeria y lo
     * alteraria. El documento no lleva datos personales, pero si el tramo de
     * plantilla, el estado de licencia y el veredicto de cada comprobacion de
     * `doctor`: un mapa util para quien este mirando la red.
     *
     * Motivo propio y no `EndpointNotConfigured` porque la accion del cliente es
     * distinta: alli falta escribir una direccion, aqui hay una escrita y hay que
     * corregirla. Un mensaje que dijera «no hay destino» teniendo uno delante
     * mandaria a esa persona a buscar donde no es.
     */
    case EndpointNotHttps = 'endpoint_not_https';

    /** La licencia no lista `telemetry`, esta caducada, ausente o no se puede verificar. */
    case NotInLicense = 'not_in_license';
}
