<?php

declare(strict_types=1);

namespace App\Support\Version;

/**
 * De donde sale la version que publica `GET /api/v1/health` (doc 02 §10.5).
 *
 * ## Por que hace falta resolverla y no basta con leer una variable
 *
 * El contrato promete SemVer: el esquema `Health` de `docs/api/openapi.yaml`
 * valida `version` contra `^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$`. Las dos
 * variables que hoy dicen «version desplegada» pueden no serlo:
 *
 *   - `APP_VERSION` la fija el build de la imagen de produccion
 *     (`infra/docker/php/Dockerfile`, `ARG APP_VERSION`). Es la mas fiable
 *     cuando existe —viaja DENTRO del artefacto y no puede desviarse de el—,
 *     pero su valor por defecto es de desarrollo.
 *   - `IMAGE_TAG` la fija el instalador en el `.env` del cliente y decide que
 *     imagen se descarga (`infra/compose.prod.yaml`). Su valor de ejemplo es
 *     `latest`, que **no es SemVer** y romperia el contrato del endpoint.
 *
 * En desarrollo y en la CI no hay ninguna de las dos con forma valida, y ahi la
 * respuesta correcta no es inventarse un `0.0.0` ni devolver `latest`: es la
 * version del propio repositorio.
 *
 * ## El orden, y por que es ese
 *
 *   1. Los valores del entorno, en el orden en que se pasan (hoy `APP_VERSION`
 *      y luego `IMAGE_TAG`), y **solo si tienen forma de SemVer**. Es el caso de
 *      produccion: quien despliega ha dicho que version es, y eso manda sobre
 *      cualquier fichero de la imagen.
 *   2. El fichero `VERSION` de la raiz del repositorio, que es la fuente de
 *      verdad versionada y la que se etiqueta al publicar. Se prueban varias
 *      rutas porque el fichero esta en sitios distintos segun donde corra el
 *      proceso; ver `config/app.php`.
 *   3. `0.0.0` como ultimo recurso. **La sonda de vida no puede fallar nunca**:
 *      un `500` porque falta un fichero de version es exactamente el reinicio en
 *      bucle que la sonda existe para evitar. `0.0.0` es ademas legible como lo
 *      que es —«esta instalacion no sabe que version tiene»— y no se confunde
 *      con ninguna publicada.
 *
 * ## Se resuelve en `config/` y no en el controlador
 *
 * Leer el fichero es la unica lectura de disco del camino de `/api/v1/health`, y
 * ocurre al cargar la configuracion —igual que cualquier otro valor de
 * `config/`—, no al atender la peticion. El controlador solo lee
 * `config('app.version')`, asi que la sonda de vida sigue sin tocar ninguna
 * dependencia: ni base de datos, ni Redis, ni disco.
 */
final class DeployedVersion
{
    /**
     * El patron del esquema `Health` del contrato, literal.
     *
     * Si aqui se afloja, el endpoint empieza a devolver respuestas que Spectator
     * rechaza en la suite de contrato: son el mismo patron a proposito.
     */
    public const string SEMVER = '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/';

    /** Ni el entorno ni el repositorio saben decir la version. */
    public const string UNKNOWN = '0.0.0';

    /**
     * @param  list<mixed>  $declared  Valores del entorno, del mas fiable al menos. Se acepta `mixed` porque vienen de `env()`.
     * @param  list<string>  $files  Rutas candidatas del fichero `VERSION`, en orden.
     */
    public static function resolve(array $declared, array $files): string
    {
        foreach ($declared as $value) {
            if (! is_string($value)) {
                continue;
            }

            $candidate = trim($value);

            if (self::isSemVer($candidate)) {
                return $candidate;
            }
        }

        foreach ($files as $path) {
            $candidate = self::firstLineOf($path);

            if ($candidate !== null && self::isSemVer($candidate)) {
                return $candidate;
            }
        }

        return self::UNKNOWN;
    }

    private static function isSemVer(string $value): bool
    {
        return preg_match(self::SEMVER, $value) === 1;
    }

    /**
     * La primera linea del fichero, sin espacios ni retorno de carro.
     *
     * Solo la primera: el fichero es «un SemVer por linea» y lo que venga
     * despues —un comentario que alguien anada, una linea en blanco de mas— no
     * puede convertir la version en algo que el contrato rechace.
     */
    private static function firstLineOf(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return trim(explode("\n", $contents, 2)[0]);
    }
}
