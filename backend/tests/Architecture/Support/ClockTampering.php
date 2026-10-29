<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use App\Modules\Shared\Application\Port\Clock;
use PhpToken;

/*
 * Las formas de tocar UNO SOLO de los dos relojes de una prueba con framework
 * (Q5 de la verificacion de la 2.1.0, `FrozenTimeTest`).
 *
 * Una prueba de Feature, Integration o Contract tiene el puerto `Clock` del
 * dominio y el reloj de Carbon. `FrozenTime` los detiene juntos; cualquier otra
 * via mueve uno y deja el otro en la hora real, y el resultado pasa a depender
 * del dia en que se ejecuta. Dos familias:
 *
 *   1. Vincular o sustituir `Clock` a mano (`instance`, `bind`,
 *      `singleton`, `scoped` y sus variantes `*If`, `extend`, los dobles
 *      `mock`, `spy` y `partialMock` de Laravel, que lo meten en el
 *      contenedor, y el contenedor como array,
 *      `$this->app[Clock::class] = …`; tambien por la facade
 *      `App::`), con el nombre corto, el nombre completo o un alias de
 *      importacion.
 *   2. Mover solo Carbon: `::setTestNow()` de cualquier clase (`Carbon`,
 *      `CarbonImmutable`, la facade `Date`, un alias), y los ayudantes de viaje
 *      en el tiempo de Laravel y Pest (`travel`, `travelTo`, `travelBack`,
 *      `freezeTime`, `freezeSecond`), que solo mueven Carbon.
 *
 * Se lee el fuente SIN comentarios (por tokens): un comentario que explique
 * por que no se usa `Carbon::setTestNow()` no es una infraccion.
 *
 * LO QUE NO VE, Y ESTA ESCRITO: el nombre de la clase dentro de una cadena
 * (`app()->instance('App\…\Clock', …)`) o en una variable. Nadie lo escribe asi
 * en este repositorio y detectarlo exigiria evaluar el codigo.
 */
final class ClockTampering
{
    /** Nombre completo del puerto, sin barra inicial. */
    public const string CLOCK_PORT = Clock::class;

    /**
     * @return list<string> Una descripcion por infraccion, en orden de aparicion.
     */
    public static function in(string $source): array
    {
        $code = self::withoutComments($source);
        $names = array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            self::namesOfThePort($code),
        );

        $offences = [];

        // Vincular, como metodo (`app()->instance(`) o como estatico de la
        // facade (`App::bind(`); y los dobles de Laravel, que son metodos de la
        // prueba (`$this->mock(`, `spy(`, `partialMock(`) porque meten el doble
        // en el contenedor. Un doble suelto —`Mockery::mock(Clock::class)`, el
        // `mock()` de Pest— no toca el contenedor: solo cuenta si despues se
        // vincula, y eso lo ve esta misma expresion.
        $binding = '/(?:(?:->|::)(?:instance|bind|singleton|scoped|bindIf|singletonIf|scopedIf|extend)|->(?:mock|spy|partialMock))\(\s*('
            .implode('|', $names).')::class/';

        if (preg_match_all($binding, $code, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $offences[] = 'vincula Clock a mano: '.$match;
            }
        }

        // El contenedor como array: `$this->app[Clock::class] = …`.
        $offsetSet = '/\[\s*('.implode('|', $names).')::class\s*\]\s*=(?![=>])/';

        if (preg_match_all($offsetSet, $code, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $offences[] = 'vincula Clock a mano: '.$match;
            }
        }

        if (preg_match_all('/::setTestNow(?:AndTimezone)?\(/', $code, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $offences[] = 'mueve solo Carbon: '.$match;
            }
        }

        // Como metodo (`$this->travelTo(`) o como funcion de Pest (`travelTo(`),
        // pero no como parte de otro nombre ni como metodo estatico ajeno.
        $timeTravel = '/(?:->|(?<![\w$>:\\\\]))(travel|travelTo|travelBack|freezeTime|freezeSecond)\(/';

        if (preg_match_all($timeTravel, $code, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $offences[] = 'mueve solo Carbon: '.$match;
            }
        }

        return $offences;
    }

    /**
     * Los nombres con los que el fichero puede referirse al puerto: el corto,
     * el completo con y sin barra inicial, y cada alias de importacion.
     *
     * @return list<string>
     */
    private static function namesOfThePort(string $code): array
    {
        $names = ['Clock', self::CLOCK_PORT, '\\'.self::CLOCK_PORT];

        $import = '/\buse\s+\\\\?'.preg_quote(self::CLOCK_PORT, '/').'\s+as\s+(\w+)\s*;/';

        if (preg_match_all($import, $code, $matches) > 0) {
            return [...$names, ...$matches[1]];
        }

        return $names;
    }

    private static function withoutComments(string $source): string
    {
        $code = '';

        foreach (PhpToken::tokenize($source) as $token) {
            if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        return $code;
    }
}
