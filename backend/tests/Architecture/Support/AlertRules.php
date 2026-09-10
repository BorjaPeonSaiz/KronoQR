<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Todas las reglas de alerta del repositorio, leidas del arbol de ficheros y no
 * de una lista escrita a mano.
 *
 * ## Por que existe esta clase y no una funcion en un fichero de prueba
 *
 * La norma del doc 02 §8.4 —cada alerta con umbral, severidad, destinatario y
 * runbook que existe— se comprobaba fichero a fichero, con la lista de ficheros
 * escrita dentro de las pruebas. `auth.yml` se quedo fuera de esa lista desde
 * que se escribio hasta la tarea 3.1: tres alertas de ataque a credenciales sin
 * que nadie comprobara que llevaban procedimiento. El descubrimiento por `glob`
 * es lo unico que impide que el proximo fichero repita la historia (decision 4
 * de la ficha 3.2).
 *
 * Vive en `Support/` y no como funcion global de un `*Test.php` por el mismo
 * motivo que `Repo`: una funcion declarada en un fichero de prueba solo existe
 * si Pest ya cargo ese fichero, y el orden de carga cambia con `--filter`. Una
 * prueba que pasa entera y falla filtrada no verifica nada.
 *
 * Se recorre con `glob` a proposito (nunca `RecursiveDirectoryIterator`): sobre
 * el montaje de Docker Desktop, el iterador recursivo omite ficheros sin avisar
 * — ya paso con 38 ficheros de `tests/Feature`, y aqui un fichero omitido seria
 * un grupo de alertas sin comprobar.
 */
final class AlertRules
{
    /** Directorio de las reglas, relativo a la raiz del repositorio. */
    public const DIRECTORY = 'infra/observability/prometheus/rules';

    /**
     * La escala de severidad, unificada en cuatro valores (decision 3).
     *
     * `info` no notifica a nadie: existe para inhibir (ventana de
     * mantenimiento). Un quinto valor inventado sobre la marcha no encuentra
     * ruta en Alertmanager y la alerta cae en el receptor por defecto, que es
     * la forma silenciosa de que no avise nadie.
     *
     * @var list<string>
     */
    public const SEVERITIES = ['critical', 'high', 'warning', 'info'];

    /**
     * Los tres destinatarios del catalogo del doc 01 §9.3 (decision 4).
     *
     * @var list<string>
     */
    public const RECIPIENTS = ['it-cliente', 'rrhh', 'seguridad'];

    /**
     * Los ficheros de reglas, en rutas relativas a la raiz del repositorio.
     *
     * @return list<string>
     */
    public static function files(): array
    {
        $absolutes = glob(Repo::file(self::DIRECTORY).'/*.yml') ?: [];

        sort($absolutes);

        return array_map(
            static fn (string $absolute): string => self::DIRECTORY.'/'.basename($absolute),
            $absolutes,
        );
    }

    /**
     * Las reglas de un fichero, ya en la forma que espera Prometheus.
     *
     * @return list<array{alert?: string, expr?: string, for?: string, labels?: array<string, string>, annotations?: array<string, string>}>
     */
    public static function inFile(string $relative): array
    {
        /** @var array{groups?: list<array{rules?: list<array<string, mixed>>}>} $document */
        $document = Yaml::parse(Repo::contents($relative));

        $rules = [];

        foreach ($document['groups'] ?? [] as $group) {
            foreach ($group['rules'] ?? [] as $rule) {
                /** @var array{alert?: string, expr?: string, for?: string, labels?: array<string, string>, annotations?: array<string, string>} $rule */
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Todas las reglas del arbol, con el fichero del que sale cada una para que
     * el mensaje de fallo diga donde mirar.
     *
     * @return list<array{file: string, alert: string, expr: string, for: string, severity: string, destinatario: string, component: string, runbook: string}>
     */
    public static function all(): array
    {
        $rules = [];

        foreach (self::files() as $relative) {
            foreach (self::inFile($relative) as $rule) {
                $rules[] = [
                    'file' => $relative,
                    'alert' => $rule['alert'] ?? '',
                    'expr' => $rule['expr'] ?? '',
                    'for' => $rule['for'] ?? '',
                    'severity' => $rule['labels']['severity'] ?? '',
                    'destinatario' => $rule['labels']['destinatario'] ?? '',
                    'component' => $rule['labels']['component'] ?? '',
                    'runbook' => $rule['annotations']['runbook_url'] ?? '',
                ];
            }
        }

        return $rules;
    }

    /**
     * Una regla por su nombre, o `null` si todavia no existe.
     *
     * Devolver `null` en vez de fallar es deliberado: el mensaje de la prueba
     * que la busca explica que alerta falta y de que fila del catalogo es, y eso
     * dice mucho mas que un "undefined index".
     *
     * @return array{file: string, alert: string, expr: string, for: string, severity: string, destinatario: string, component: string, runbook: string}|null
     */
    public static function named(string $alert): ?array
    {
        foreach (self::all() as $rule) {
            if ($rule['alert'] === $alert) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Los nombres de todas las alertas declaradas.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            static fn (array $rule): string => $rule['alert'],
            self::all(),
        );
    }
}
