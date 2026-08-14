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
     * Son dos y estan enumerados a proposito, en vez de dejar el campo abierto:
     * RNF-M-05 (presupuesto del 15 % de deuda tecnica por iteracion) y RQ-12
     * (nada se da por terminado sin la Definicion de Terminado). Los dos son de
     * proceso y ninguna prueba puede afirmarlos.
     *
     * El campo existe porque la alternativa era peor. Sin el, cada uno deja un
     * rojo permanente en `--check`, y un `--check` que siempre esta en rojo se
     * acaba ignorando: el dia que falte una prueba de verdad, nadie mirara.
     * Enumerarlos aqui, y no admitir cualquier valor, es lo que impide que
     * `verificacion: revision` se convierta en la via de escape para no escribir
     * una prueba que si se puede escribir.
     */
    public const array REVIEWED_BY_HAND = ['RNF-M-05', 'RQ-12'];

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
