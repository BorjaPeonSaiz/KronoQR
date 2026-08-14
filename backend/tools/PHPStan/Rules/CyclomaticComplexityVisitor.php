<?php

declare(strict_types=1);

namespace KronoQR\Tools\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Cuenta los puntos de decision de un cuerpo de funcion.
 *
 * Ver {@see MethodCyclomaticComplexityRule} para la metrica y su origen
 * documental. Este visitante solo suma; la decision de si el numero es
 * aceptable es de la regla.
 */
final class CyclomaticComplexityVisitor extends NodeVisitorAbstract
{
    /**
     * Nodos que abren una rama y suman un punto cada uno.
     *
     * Es una lista y no una cadena de `instanceof` para que esta misma clase
     * cumpla el limite que verifica: una cadena de veinte comparaciones tiene
     * complejidad veinte, y la regla se marcaba a si misma.
     *
     * @var list<class-string<Node>>
     */
    private const array BRANCHING_NODES = [
        Stmt\If_::class,
        Stmt\ElseIf_::class,
        Stmt\Catch_::class,
        Stmt\For_::class,
        Stmt\Foreach_::class,
        Stmt\While_::class,
        Stmt\Do_::class,
        Ternary::class,
        BinaryOp\Coalesce::class,
        AssignOp\Coalesce::class,
        BinaryOp\BooleanAnd::class,
        BinaryOp\BooleanOr::class,
        BinaryOp\LogicalAnd::class,
        BinaryOp\LogicalOr::class,
        BinaryOp\LogicalXor::class,
    ];

    /**
     * Un camino siempre existe: el que no toma ninguna rama.
     */
    public int $complexity = 1;

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            // La funcion anidada tiene su propio presupuesto: PHPStan la visita
            // por separado y la regla la mide entonces.
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        $this->complexity += $this->decisionPoints($node);

        return null;
    }

    private function decisionPoints(Node $node): int
    {
        if ($node instanceof MatchArm) {
            // conds === null es el brazo por defecto: no ramifica.
            return count($node->conds ?? []);
        }

        if ($node instanceof Stmt\Case_) {
            // El `default:` de un switch tampoco ramifica.
            return $node->cond === null ? 0 : 1;
        }

        return in_array($node::class, self::BRANCHING_NODES, true) ? 1 : 0;
    }
}
