<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

/**
 * Una fila de docs/requisitos.yaml: un requisito, ya expandido, con la fase en
 * la que lo construye el plan y su enunciado del doc 01.
 */
final readonly class Requirement
{
    /**
     * Requisitos que NO verifica una herramienta, sino una persona.
     *
     * Son tres y estan enumerados a proposito, en vez de dejar el campo abierto:
     *
     * - **RNF-M-05** — presupuesto del 15 % de deuda tecnica por iteracion.
     * - **RQ-12** — nada se da por terminado sin la Definicion de Terminado.
     * - **RL-16** — *el cliente es responsable del tratamiento y operador del
     *   sistema*. Añadido al cerrar la Fase 5. Es el unico requisito legal de la
     *   lista y merece explicacion: RL-16 no describe ningun comportamiento del
     *   producto, sino **quien responde ante la Inspeccion y ante su plantilla**,
     *   que es una atribucion juridica del despliegue y no un efecto observable
     *   del codigo. Este repositorio no puede afirmarla ni negarla: la
     *   instalacion vive en el servidor del cliente y el fabricante no la ve
     *   (ADR-020). Lo que si depende de este repositorio —que el documento
     *   entregado se lo DIGA, con esas palabras— ya lo comprueba
     *   `ClientDocumentationTest`, que sigue etiquetando RL-16; lo que no
     *   depende, y por eso esta aqui, es que el cliente lo asuma.
     *
     *   No confundir con RL-17 («el fabricante no aloja ni accede»), que si es
     *   comportamiento y si tiene pruebas: `OutboundChannelsTest`,
     *   `TelemetryIsolationTest`, `LicenseVerificationIsLocalTest` y
     *   `SupportAccessAuditTrailTest`. Que el gemelo de RL-16 se pueda probar es
     *   justo lo que obliga a justificar por que RL-16 no.
     *
     * El campo existe porque la alternativa era peor. Sin el, cada uno deja un
     * rojo permanente en `--check`, y un `--check` que siempre esta en rojo se
     * acaba ignorando: el dia que falte una prueba de verdad, nadie mirara.
     * Enumerarlos aqui, y no admitir cualquier valor, es lo que impide que
     * `verificacion: revision` se convierta en la via de escape para no escribir
     * una prueba que si se puede escribir.
     */
    public const array REVIEWED_BY_HAND = ['RNF-M-05', 'RQ-12', 'RL-16'];

    public function __construct(
        public string $id,
        public int $phase,
        public string $title,
        public bool $reviewedByHand = false,
    ) {}

    /**
     * Si lo verifica una persona, no bloquea la CI: aparece en la matriz como
     * tal, con su nombre y su motivo, que es la unica evidencia honesta que se
     * puede dar de el.
     */
    public function requiresTest(): bool
    {
        return ! $this->reviewedByHand;
    }
}
