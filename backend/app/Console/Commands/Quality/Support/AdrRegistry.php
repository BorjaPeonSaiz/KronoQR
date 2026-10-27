<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Las dos caras de una decision de arquitectura: la fila del §4 del doc 02 y el
 * fichero de docs/adr/.
 *
 * El orden de autoridad de CLAUDE.md pone `docs/adr/` en el escalon 1 y el doc
 * 02 en el 4. Una decision que solo existe como fila de tabla esta registrada en
 * el sitio equivocado: se puede leer el resumen, pero no el contexto, las
 * alternativas descartadas ni las consecuencias, que es justo lo que hace falta
 * el dia que alguien quiere revertirla. ADR-026, ADR-027 y ADR-028 vivieron asi.
 *
 * Se mira en las dos direcciones a proposito. Un fichero sin fila tambien es una
 * divergencia: el §4 es el indice por el que se entra, y una decision que no
 * figura alli no la encuentra quien no sabe que existe.
 */
final readonly class AdrRegistry
{
    /** El §4 del doc 02, hasta el siguiente encabezado de nivel 2. */
    private const string HEADING = '/^##\s+4\.\s+[^\n]*$/mu';

    private const string NEXT_HEADING = '/^##\s+/mu';

    /** Fila de la tabla: `| **026** | La correccion supersede | ... |`. */
    private const string TABLE_ROW = '/^\|\s*\*\*(?<number>\d{3})\*\*\s*\|\s*(?<title>[^|]*)\|/mu';

    /** Nombre de fichero: `ADR-026-la-correccion-supersede.md`. */
    private const string FILE_NAME = '/^ADR-(?<number>\d{3})-[a-z0-9-]+\.md$/';

    /** El estado que la fila del §4 resume en cursiva: `*(aceptada; … pendiente)*`. */
    private const string ROW_STATUS = '/\*\((?<status>.*?)\)\*/u';

    /** La fila «Estado» de la cabecera del ADR. */
    private const string FILE_STATUS = '/^\|\s*\*\*Estado\*\*\s*\|(?<status>[^\n]*)$/mu';

    /**
     * Palabras de estado que el indice no puede afirmar si el ADR no las dice.
     * Un indice que dice «pendiente» o «propuesta» de una decision ya aceptada e
     * implementada manda a quien lo lee a esperar algo que ya ocurrio.
     */
    private const array INDEX_STATUS_WORDS = ['pendiente', 'propuesta', 'rechazada', 'sustituida'];

    /**
     * Palabras de estado que el ADR no puede decir sin que el indice las diga:
     * una decision que no esta en vigor no puede figurar en el indice como si lo
     * estuviera.
     */
    private const array FILE_STATUS_WORDS = ['propuesta', 'rechazada', 'sustituida'];

    /**
     * @param  array<array-key, string>  $cited  Numero -> titulo de la fila del §4.
     * @param  array<array-key, string>  $files  Numero -> nombre del fichero de docs/adr/.
     * @param  array<array-key, string>  $states  Numero -> fila «Estado» del ADR.
     */
    private function __construct(public array $cited, public array $files, public array $states = []) {}

    public static function fromPaths(string $stackFile, string $adrDirectory): self
    {
        $files = self::filesIn($adrDirectory);

        return new self(self::rows($stackFile), $files, self::statesOf($adrDirectory, $files));
    }

    /**
     * Filas del §4 cuyo estado en cursiva dice algo que la fila «Estado» de su
     * ADR no dice, o al reves. Numero -> descripcion de la divergencia.
     *
     * Se compara por palabras y no por frases: el indice resume y el ADR
     * detalla, y lo unico que se exige es que el resumen no afirme un estado
     * que el ADR ya no tiene.
     *
     * @return array<array-key, string>
     */
    public function statusDivergences(): array
    {
        $divergences = [];

        foreach ($this->states as $number => $state) {
            $title = $this->cited[$number] ?? null;

            if ($title === null) {
                continue;
            }

            $rowStatus = preg_match(self::ROW_STATUS, $title, $match) === 1 ? mb_strtolower($match['status']) : '';
            $fileStatus = mb_strtolower($state);

            $divergence = self::wordOnlyIn($rowStatus, $fileStatus, self::INDEX_STATUS_WORDS, 'el indice dice «%s» y la fila «Estado» del ADR no')
                ?? self::wordOnlyIn($fileStatus, $rowStatus, self::FILE_STATUS_WORDS, 'la fila «Estado» del ADR dice «%s» y el indice no');

            if ($divergence !== null) {
                $divergences[$number] = $divergence;
            }
        }

        return $divergences;
    }

    /**
     * La primera palabra de `$words` que `$says` contiene y `$silent` no, ya
     * escrita en `$message`; `null` si no hay ninguna.
     *
     * @param  list<string>  $words
     */
    private static function wordOnlyIn(string $says, string $silent, array $words, string $message): ?string
    {
        foreach ($words as $word) {
            if (str_contains($says, $word) && ! str_contains($silent, $word)) {
                return \sprintf($message, $word);
            }
        }

        return null;
    }

    /**
     * Decisiones que el §4 cita y que no tienen ADR escrito.
     *
     * @return array<array-key, string>
     */
    public function citedWithoutFile(): array
    {
        return array_diff_key($this->cited, $this->files);
    }

    /**
     * ADR escritos que ninguna fila del §4 anuncia.
     *
     * @return array<array-key, string>
     */
    public function fileWithoutRow(): array
    {
        return array_diff_key($this->files, $this->cited);
    }

    /**
     * @return array<array-key, string>
     */
    private static function rows(string $stackFile): array
    {
        if (! is_file($stackFile)) {
            throw new RuntimeException(
                'No se encuentra el documento de stack e implementacion en '.$stackFile.'. '
                .'Dentro del contenedor llega por el montaje ../docs de infra/compose.dev.yaml.'
            );
        }

        preg_match_all(self::TABLE_ROW, self::section((string) file_get_contents($stackFile)), $matches, PREG_SET_ORDER);

        $cited = [];

        foreach ($matches as $match) {
            // La clave se fuerza a cadena: «026» es un numero de ADR, no un
            // entero, y PHP convertiria «100» en int rompiendo el array_diff_key
            // contra los nombres de fichero.
            $cited[(string) $match['number']] = trim(str_replace('**', '', $match['title']));
        }

        if ($cited === []) {
            throw new RuntimeException(
                'El §4 del doc 02 no cita ningun ADR: la tabla ha cambiado de forma y este comando ya no '
                .'la reconoce. Cada fila empieza por el numero entre asteriscos, «| **001** | ...».'
            );
        }

        ksort($cited);

        return $cited;
    }

    private static function section(string $markdown): string
    {
        if (preg_match(self::HEADING, $markdown, $match, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('El doc 02 no tiene un encabezado «## 4. …» con la tabla de ADR.');
        }

        $rest = substr($markdown, $match[0][1] + strlen($match[0][0]));

        if (preg_match(self::NEXT_HEADING, $rest, $next, PREG_OFFSET_CAPTURE) === 1) {
            return substr($rest, 0, $next[0][1]);
        }

        return $rest;
    }

    /**
     * @return array<array-key, string>
     */
    private static function filesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            throw new RuntimeException('No se encuentra el directorio de ADR en '.$directory.'.');
        }

        $files = [];

        foreach (Finder::create()->files()->in($directory)->depth(0)->name('*.md')->sortByName() as $file) {
            if (preg_match(self::FILE_NAME, $file->getFilename(), $match) !== 1) {
                throw new RuntimeException(
                    'El fichero '.$file->getFilename().' esta en docs/adr/ y no se llama ADR-NNN-titulo-en-minusculas.md. '
                    .'Renombralo: el numero del nombre es lo que enlaza el ADR con su fila del §4 del doc 02.'
                );
            }

            // Misma razon que en rows(): el numero de ADR es una cadena con
            // ceros a la izquierda, no un entero.
            $files[(string) $match['number']] = $file->getFilename();
        }

        ksort($files);

        return $files;
    }

    /**
     * La fila «Estado» de cada ADR, o cadena vacia si no la tiene.
     *
     * @param  array<array-key, string>  $files
     * @return array<array-key, string>
     */
    private static function statesOf(string $directory, array $files): array
    {
        $states = [];

        foreach ($files as $number => $file) {
            $content = (string) file_get_contents($directory.'/'.$file);
            $states[$number] = preg_match(self::FILE_STATUS, $content, $match) === 1 ? trim($match['status']) : '';
        }

        return $states;
    }
}
