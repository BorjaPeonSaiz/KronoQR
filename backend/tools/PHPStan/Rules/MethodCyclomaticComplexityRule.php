<?php

declare(strict_types=1);

namespace KronoQR\Tools\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Complejidad ciclomatica maxima por metodo (doc 02 §3.5, fila «Complejidad»).
 *
 * El §3.5 exige «complejidad ciclomatica <= 10 por metodo» y nombra a PHPStan
 * como la herramienta que lo verifica, pero PHPStan no trae esa regla y los
 * paquetes de la comunidad miden complejidad *cognitiva*, que es otra metrica
 * y da otro numero. Se implementa aqui para que la convencion la compruebe una
 * herramienta y no una persona, que es la regla que gobierna el §3.5.
 *
 * Metrica: la de McCabe. Se parte de 1 y se suma un punto por cada punto de
 * decision del cuerpo:
 *
 *   if · elseif · case con condicion · catch · for · foreach · while · do
 *   operador ternario · ?? · ??= · && · || · and · or · xor
 *   cada condicion de un brazo de match (el brazo por defecto no suma)
 *
 * Las funciones anidadas (closures y funciones flecha) **no** suman en la
 * complejidad de quien las contiene: PHPStan las visita por separado y cada
 * una tiene su propio presupuesto. Contarlas dos veces penalizaria el estilo
 * funcional sin que la ramificacion real del metodo hubiera crecido.
 *
 * @implements Rule<FunctionLike>
 */
final readonly class MethodCyclomaticComplexityRule implements Rule
{
    public function __construct(private int $maxComplexity) {}

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $statements = $node->getStmts();

        if ($statements === null) {
            // Metodo abstracto o declarado en una interfaz: no hay cuerpo que medir.
            return [];
        }

        $counter = new CyclomaticComplexityVisitor;

        $traverser = new NodeTraverser($counter);
        $traverser->traverse($statements);

        if ($counter->complexity <= $this->maxComplexity) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s tiene complejidad ciclomatica %d, por encima del maximo de %d (doc 02 §3.5). Extrae las ramas a metodos con nombre, o sustituye la cadena de condiciones por un match.',
                $this->describe($node, $scope),
                $counter->complexity,
                $this->maxComplexity,
            ))
                ->identifier('kronoqr.cyclomaticComplexity')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private function describe(FunctionLike $node, Scope $scope): string
    {
        if ($node instanceof Stmt\ClassMethod) {
            $class = $scope->getClassReflection();

            return sprintf(
                'El metodo %s%s()',
                $class !== null ? $class->getDisplayName().'::' : '',
                $node->name->toString(),
            );
        }

        if ($node instanceof Stmt\Function_) {
            return sprintf('La funcion %s()', $node->name->toString());
        }

        return 'La funcion anonima';
    }
}
