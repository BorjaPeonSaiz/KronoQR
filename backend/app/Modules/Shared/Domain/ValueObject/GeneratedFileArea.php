<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Donde vive una clase de fichero y que nombres le pertenecen (ADR-045, C3).
 *
 * ## El confinamiento se declara aqui y se aplica en el adaptador
 *
 * Una purga **solo** actua sobre lo que esta en `root`, a un nivel, y cuyo nombre
 * casa con `pattern`. Lo demas no se toca, aunque este en el mismo directorio:
 * con `REPORTING_EXPORT_PATH` apuntado por error a `storage/app`, la purga de
 * informes ve `exports/`, `legal-exports/` y `diagnostics/`, ninguno casa con un
 * `uuid`, y no borra nada. Antes de esta clase, cualquier subdirectorio pasaba
 * por un `uuid`.
 *
 * ## El patron es exacto y termina en `\z`
 *
 * Los patrones del catalogo llevan el modificador `D`: sin el, el `$` de PCRE
 * casa tambien antes de un salto de linea final, y `«nombre»\n` pasaria por un
 * nombre valido. Un nombre con `/` no casa con ninguno, asi que la comprobacion
 * tampoco puede escapar de la raiz.
 *
 * Es un valor puro (regla dura 1): no abre ningun fichero. Quien lee el disco es
 * el adaptador del puerto `GeneratedFileStore`.
 */
final readonly class GeneratedFileArea
{
    public function __construct(
        public GeneratedFileClass $class,
        /** Raiz de la clase, tal como la da la configuracion. */
        public string $root,
        /** Expresion regular completa, con delimitadores, anclas y modificador `D`. */
        public string $pattern,
        public GeneratedFileShape $shape,
    ) {}

    /** ¿Este nombre de entrada pertenece a la clase? */
    public function admits(string $name): bool
    {
        return preg_match($this->pattern, $name) === 1;
    }

    public function holdsDirectories(): bool
    {
        return $this->shape === GeneratedFileShape::Directory;
    }
}
