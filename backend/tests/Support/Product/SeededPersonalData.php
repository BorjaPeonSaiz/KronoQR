<?php

declare(strict_types=1);

namespace Tests\Support\Product;

/**
 * Los datos personales **ficticios** que se siembran en el historico de errores
 * para comprobar que no salen (ADR-048, RF-PD-15, RL-19; dictamen de seguridad
 * del bloque 19).
 *
 * Todo es inventado y evidente: nombres frecuentes con apellidos que no existen
 * («Rosa Ficticiana»), documentos y cuentas con la forma correcta y el control
 * inventado, telefonos de los rangos reservados para ficcion o de ejemplo.
 * Cada valor va **en todas sus formas** de caja y de separador, porque la fuga
 * que cerro ADR-048 era justo esa: la forma que el patron no esperaba.
 *
 * Lo usan las pruebas de las dos puertas (`ClientErrorsPiiTest`,
 * `HeartbeatClientErrorsPiiTest`) y lo deja listo para la prueba de volumen
 * sembrada de `qa-testing`: {@see self::all()} da los valores, {@see self::leaksIn()}
 * busca cada uno con limites de palabra y sin distinguir mayusculas, y
 * {@see self::uuidsIn()} hace lo mismo con los `employees.uuid` (H2).
 */
final class SeededPersonalData
{
    /**
     * Las personas, cada una en sus cuatro formas: Capitalizada, MAYUSCULAS,
     * minusculas y «Apellido, Nombre». Mas las dos que pidio seguridad: un
     * apellido chino de dos letras y un nombre que coincide con una palabra
     * tecnica habitual (`max`).
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $people = [['Rosa', 'Ficticiana'], ['Luz', 'Inventadez'], ['Will', 'Testerson']];
        $forms = [];

        foreach ($people as [$given, $family]) {
            $full = $given.' '.$family;
            $forms[] = $full;
            $forms[] = mb_strtoupper($full);
            $forms[] = mb_strtolower($full);
            $forms[] = $family.', '.$given;
        }

        return [...$forms, 'Li Wang', 'Max Campos', 'José Ñúñez Ficticio', 'McInventado', "O'Testerson"];
    }

    /**
     * Las palabras sueltas de los nombres que no pueden sobrevivir en ninguna
     * forma. Los apellidos inventados son los que dan la medida: un nombre
     * frecuente suelto como «Rosa» lo cubre `ErrorVocabularyTest`.
     *
     * @return list<string>
     */
    public static function nameParts(): array
    {
        return ['Ficticiana', 'Inventadez', 'Testerson', 'Wang', 'Campos', 'Ñúñez', 'Ficticio', 'McInventado'];
    }

    /** @return list<string> */
    public static function emails(): array
    {
        return ['rosa.ficticiana@hotel-ejemplo.es', 'josé.ñúñez@hotel-ejemplo.es', 'ROSA.FICTICIANA@HOTEL-EJEMPLO.ES'];
    }

    /**
     * DNI, NIE y pasaportes, con y sin separadores, en las dos cajas.
     *
     * @return list<string>
     */
    public static function documents(): array
    {
        return [
            '45678912K', '45.678.912-K', '45 678 912 K', '45678912',
            'X7654321L', 'x-7654321-l', 'Y 7.654.321 M',
            'PAA654321', 'K98765432', 'k98765432', 'AB1234567',
        ];
    }

    /** @return list<string> */
    public static function socialSecurityNumbers(): array
    {
        return ['28/12345678/40', '281234567840', '28 12345678 40', '28-1234567-40'];
    }

    /** @return list<string> */
    public static function bankAccounts(): array
    {
        return [
            'ES91 2100 0418 4502 0005 1332', 'es91 2100 0418 4502 0005 1332', 'ES9121000418450200051332',
            'ES91-2100-0418-4502-0005-1332', 'DE89370400440532013000', 'de89 3704 0044 0532 0130 00',
            '4111111111111111', '4111 1111 1111 1111', '4111-1111-1111-1111',
            'be71096123456769', 'BE71 0961 2345 6769', 'be71-0961-2345-6769',
            'fr7630006000011234567890189', 'FR76 3000 6000 0112 3456 7890 189', 'fr76-3000-6000-0112-3456-7890-189',
            'it60x0542811101000000123456', 'IT60 X054 2811 1010 0000 0123 456',
            'pt50000201231234567890154', 'PT50-0002-0123-1234-5678-9015-4',
            'gb29nwbk60161331926819', 'GB29 NWBK 6016 1331 9268 19',
        ];
    }

    /** @return list<string> */
    public static function phones(): array
    {
        return [
            '612 345 678', '612345678', '91 234 56 78', '912 34 56 78', '+34 612 345 678', '0034612345678',
            '+44 20 7946 0958', '+33 1 23 45 67 89',
        ];
    }

    /**
     * Codigos de empleado: canonico en las dos cajas, heredado alfanumerico y
     * numerico, y los alfanumericos cortos que cerro H4.
     *
     * @return list<string>
     */
    public static function employeeCodes(): array
    {
        return ['E7K2M9QX4B', 'e7k2m9qx4b', 'HTL2019X0042', '739104', '#739104', 'AB12C3', 'a1b2c3', 'x7k2m9'];
    }

    /**
     * Lo demas que pidio seguridad: matriculas, codigo postal y un año de
     * nacimiento.
     *
     * @return list<string>
     */
    public static function others(): array
    {
        return ['1234 BCD', 'M-1234-AB', '28013', '1985'];
    }

    /**
     * Todo, agrupado por clase de dato.
     *
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        return [
            'names' => [...self::names(), ...self::nameParts()],
            'emails' => self::emails(),
            'documents' => self::documents(),
            'social_security' => self::socialSecurityNumbers(),
            'bank' => self::bankAccounts(),
            'phones' => self::phones(),
            'employee_codes' => self::employeeCodes(),
            'others' => self::others(),
        ];
    }

    /**
     * Un mensaje como lo escribiria quien no esta pensando en la regla dura 21:
     * una persona y un dato de cada clase, mas un trozo tecnico que tiene que
     * salir legible (el control positivo).
     */
    public static function message(int $index = 0): string
    {
        $names = self::names();
        $documents = self::documents();
        $phones = self::phones();
        $banks = self::bankAccounts();
        $codes = self::employeeCodes();
        $nss = self::socialSecurityNumbers();
        $emails = self::emails();
        $others = self::others();

        return self::TECHNICAL.' para '.$names[$index % \count($names)]
            .' doc '.$documents[$index % \count($documents)]
            .' NAF '.$nss[$index % \count($nss)]
            .' cuenta '.$banks[$index % \count($banks)]
            .' tel '.$phones[$index % \count($phones)]
            .' cod '.$codes[$index % \count($codes)]
            .' correo '.$emails[$index % \count($emails)]
            .' y '.$others[$index % \count($others)];
    }

    /** El trozo tecnico de {@see self::message()}: tiene que salir tal cual. */
    public const string TECHNICAL = 'TypeError: Cannot read properties of undefined';

    /**
     * Cuantos mensajes distintos hacen falta para que cada valor aparezca al
     * menos una vez en {@see self::message()}.
     */
    public static function messageCount(): int
    {
        return max(1, ...array_map(\count(...), array_values(self::all())));
    }

    /**
     * Errores de cliente con la forma del contrato y los datos sembrados en
     * todos los sitios por los que pueden entrar (ADR-048, dictamen de
     * seguridad): el mensaje, el valor de **cada** clave de texto admitida,
     * claves que no estan en la lista (con un nombre por clave), valores
     * anidados, `employee_uuid` y `device_id` en el cuerpo, y `app_version`.
     *
     * Doce claves como mucho por contexto (el techo del contrato), asi que los
     * lugares se reparten entre varios informes; y tantos informes como hagan
     * falta para que cada valor salga al menos una vez en un mensaje.
     *
     * @param  list<string>  $codes  Codigos del catalogo del origen, que se van alternando.
     * @param  bool  $nested  El latido rechaza los valores anidados con `400`; el panel los descarta.
     * @return list<array<string, mixed>>
     */
    public static function clientReports(array $codes, bool $nested = true): array
    {
        $names = self::names();
        $reports = [];

        for ($index = 0; $index < self::messageCount(); $index++) {
            $reports[] = [
                'code' => $codes[$index % \count($codes)],
                'occurred_at' => '2026-10-03T09:00:00Z',
                'app_version' => $index === 0 ? 'RosaFicticiana' : '2.2.0',
                'context' => ['message' => self::message($index)],
            ];
        }

        $person = static fn (int $i): string => $names[$i % \count($names)];

        // Cada clave de texto admitida, con un dato distinto.
        $reports[] = [
            'code' => $codes[0],
            'occurred_at' => '2026-10-03T09:00:00Z',
            'app_version' => '2.2.0',
            'context' => [
                'message' => self::TECHNICAL.' en '.$person(1),
                'component' => $person(2),
                'scope' => $person(3),
                'reason' => self::documents()[0].' '.$person(4),
                'cause' => self::phones()[0],
                'hook' => self::emails()[1],
                'source' => 'https://kiosk.hotel-ejemplo.es/assets/index.js?u='.self::employeeCodes()[0].'#'.$person(5),
                'error_type' => self::bankAccounts()[1],
                'outcome' => self::socialSecurityNumbers()[0],
                'kind' => self::employeeCodes()[2],
                'problem_type' => 'urn:kronoqr:problem:'.$person(6),
                'audio_state' => $person(7),
            ],
        ];

        // Claves fuera de la lista, identificadores en el cuerpo y anidados.
        $outside = [
            'message' => self::TECHNICAL.' con claves',
            $person(8) => 'x',
            'first_name' => 'Rosa',
            'last_name' => 'Ficticiana',
            'full' => $person(9),
            'employee_uuid' => $person(10),
            'device_id' => $person(11),
            'email' => self::emails()[0],
        ];

        if ($nested) {
            $outside['meta'] = ['name' => $person(12), 'phone' => self::phones()[1]];
            $outside['reason'] = ['nombre' => $person(13)];
        }

        $reports[] = [
            'code' => $codes[0],
            'occurred_at' => '2026-10-03T09:00:00Z',
            'app_version' => '2.2.0',
            'context' => $outside,
        ];

        return $reports;
    }

    /**
     * El texto de las columnas de `error_events` que pueden llevar algo
     * escrito por un cliente, sin `id`, huellas ni instantes: un `id` 1985 de
     * la secuencia daria un falso positivo con el año sembrado.
     *
     * @param  iterable<object>  $rows
     */
    public static function textOfRows(iterable $rows): string
    {
        $texts = [];

        foreach ($rows as $row) {
            foreach (['message', 'context', 'app_version', 'code', 'exception_class', 'file', 'module', 'employee_uuid', 'device_id', 'trace_id'] as $column) {
                $value = $row->{$column} ?? null;
                $texts[] = \is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }

        return implode("\n", $texts);
    }

    /**
     * Los valores sembrados que aparecen en `$haystack`, sin distinguir
     * mayusculas y **con limites de palabra**: que «Luz» no de positivo con
     * «luzbel», ni «1985» dentro de un numero mas largo. Vacia si no se ha
     * escapado ninguno.
     *
     * Un aviso para quien la use sobre el paquete ENTERO: las claves de otras
     * secciones tambien son texto (`max_…`), y el guion bajo no es una letra.
     * Buscar en la seccion `error_events`, o quitar antes las claves.
     *
     * @param  iterable<string>  $values
     * @return list<string>
     */
    public static function leaksIn(string $haystack, iterable $values): array
    {
        $leaks = [];

        foreach ($values as $value) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $haystack) === 1) {
                $leaks[] = $value;
            }
        }

        return $leaks;
    }

    /**
     * Todos los valores de {@see self::all()} que aparecen en `$haystack`.
     *
     * @return list<string>
     */
    public static function allLeaksIn(string $haystack): array
    {
        return self::leaksIn($haystack, array_merge(...array_values(self::all())));
    }

    /**
     * Los UUID de `$uuids` que aparecen en `$haystack`, en cualquier caja (H2:
     * en el paquete anonimizado no puede salir ninguno de los `employees.uuid`).
     *
     * @param  iterable<string>  $uuids
     * @return list<string>
     */
    public static function uuidsIn(string $haystack, iterable $uuids): array
    {
        $found = [];
        $lower = mb_strtolower($haystack);

        foreach ($uuids as $uuid) {
            if (str_contains($lower, mb_strtolower($uuid))) {
                $found[] = $uuid;
            }
        }

        return $found;
    }
}
