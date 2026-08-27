<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

/**
 * Catalogo cerrado de motivos de correccion (doc 01 Anexo C, RF-PA-04).
 *
 * **Los codigos van en español y en mayusculas, y es la unica excepcion** a la
 * regla de que el codigo se escribe en ingles (doc 02 §3.5). No son
 * identificadores: son **valores** del Anexo C, se escriben tal cual en
 * `shift_corrections.reason_code`, salen por la API y se leen en la exportacion
 * legal. Traducirlos a ingles obligaria a mantener un diccionario entre lo que
 * dice el documento y lo que hay en la columna, que es exactamente la segunda
 * forma de nombrar la misma cosa que el lenguaje ubicuo existe para evitar. El
 * texto que ve el usuario vive en `i18n` y se resuelve por este codigo.
 *
 * **Un enum y no una cadena libre.** `reason_code` es la columna por la que se
 * agrupa la metrica `manual_corrections_total{reason_code}` y por la que una
 * inspeccion pregunta «cuantas correcciones por olvido de fichaje hubo en
 * marzo». Con texto libre, la misma causa acaba escrita de tres formas y esa
 * consulta no se puede escribir.
 *
 * **El catalogo no es configuracion.** La regla dura 13 obliga a que las
 * diferencias entre clientes sean configuracion, pero esto no es una diferencia
 * entre clientes: es el vocabulario con el que el producto explica ante
 * Inspeccion por que un registro horario se rectifico. Ampliar el catalogo es un
 * cambio del Anexo C, revisable, no un valor en una tabla.
 */
enum CorrectionReasonCode: string
{
    /** El empleado entro a trabajar y no ficho la entrada. */
    case OLVIDO_FICHAJE_ENTRADA = 'OLVIDO_FICHAJE_ENTRADA';

    /** El empleado termino y no ficho la salida: el tramo quedo abierto. */
    case OLVIDO_FICHAJE_SALIDA = 'OLVIDO_FICHAJE_SALIDA';

    /** El quiosco no estaba operativo: sin corriente, sin camara, sin red mas alla de la cola. */
    case FALLO_TECNICO_QUIOSCO = 'FALLO_TECNICO_QUIOSCO';

    /** La tarjeta se quedo en la taquilla, se perdio o esta deteriorada (doc 01 Anexo C). */
    case TARJETA_NO_DISPONIBLE = 'TARJETA_NO_DISPONIBLE';

    /** Todavia no se le habia entregado su tarjeta: el primer dia (ADR-034). */
    case CREDENCIAL_NO_ENTREGADA = 'CREDENCIAL_NO_ENTREGADA';

    /** Dos escaneos produjeron dos tramos donde solo hubo uno. */
    case ERROR_DE_ESCANEO_DUPLICADO = 'ERROR_DE_ESCANEO_DUPLICADO';

    /** Rectificacion pactada con la persona y con RRHH, no un error del sistema. */
    case AJUSTE_ACORDADO_CON_RRHH = 'AJUSTE_ACORDADO_CON_RRHH';

    /** Jornadas anteriores a la puesta en marcha o a la alta del empleado en el sistema. */
    case ALTA_RETROACTIVA = 'ALTA_RETROACTIVA';

    /**
     * Ninguno de los anteriores.
     *
     * **Obliga a texto libre de al menos 20 caracteres** (Anexo C). El umbral no
     * es una preferencia de estilo: «error», «ajuste» o «lo dijo Marta» no
     * explican nada ante Inspeccion, y un motivo que no explica convierte RN-13
     * en un formulario. Quien no sabe que poner tiene ocho codigos que si dicen
     * algo.
     */
    case OTROS = 'OTROS';

    /**
     * Si este codigo exige explicacion escrita.
     *
     * Un predicado y no un `=== self::OTROS` repartido por el codigo: el dia que
     * otro codigo del Anexo C pase a exigir texto, se cambia aqui y no en cada
     * sitio que lo compruebe.
     */
    public function needsExplanation(): bool
    {
        return $this === self::OTROS;
    }
}
