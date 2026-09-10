<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\InvalidSystemEventPayload;

/**
 * El payload de un asiento `system.*`, con su **lista cerrada de claves por
 * accion** (RF-PD-10, RL-04, RS-07, reglas duras 6 y 21, tarea 5.7).
 *
 * ## Por que existe un objeto y no un array
 *
 * Este es el unico payload de `audit_log` que **no lo construye codigo del
 * producto**: lo arma `update.sh`, un script de shell, en el peor momento
 * posible —una actualizacion que acaba de fallar a las tres de la manana— y lo
 * pasa por la linea de comandos. Todo lo que en el resto del catalogo garantiza
 * el tipado, aqui hay que garantizarlo a mano.
 *
 * Lo que se protege es concreto:
 *
 * 1. **Ninguna clave fuera de la lista.** Si el script empieza a colar campos,
 *    el asiento deja de ser consultable y el catalogo deja de significar nada.
 *    Anadir una clave es una decision de este fichero, no del script.
 * 2. **Ningun dato personal y ningun secreto** (regla dura 21). Se rechaza por
 *    **forma**: correo, DNI/NIE y cualquier cosa con pinta de ruta absoluta.
 *    `audit_log` no admite `UPDATE` ni `DELETE`: un dato personal que entra aqui
 *    ya no se puede quitar, solo explicar.
 * 3. **Nada de texto libre.** `reason` y `failed_step` son vocabularios cerrados
 *    ({@see SystemRestoreReason}, {@see SystemUpdateStep}) porque un mensaje de
 *    error traducido no se puede agrupar dos anos despues.
 *
 * ## `chain_before` no sustituye a `prev_hash`
 *
 * La huella del ultimo eslabon anterior al asiento la escribe la propia cadena
 * en su columna, y ahi es donde la verifica `compliance:verify-audit-chain`. El
 * `chain_before` del payload es otra cosa: **la punta que el instalador leyo
 * justo antes de la operacion que el asiento describe**.
 *
 * - En `system.updated` es la punta verificada en las comprobaciones previas,
 *   antes de tocar nada. Coincide con `prev_hash` si —como debe ser— nada mas
 *   escribio durante el mantenimiento, y `chain_after` lo confirma.
 * - En `system.restored_from_backup` es **la punta de la cadena que se
 *   descarta**, leida en el ultimo instante en que todavia existia. Ahi esta
 *   toda la prueba: `chain_before` no encaja con el `prev_hash` de la fila, y
 *   esa discrepancia —la unica que hay— es lo que acredita, dentro del propio
 *   registro, que hubo un intervalo que ya no esta.
 */
final readonly class SystemEventPayload
{
    /**
     * Claves obligatorias y opcionales de cada accion del ciclo de vida de la
     * instalacion.
     *
     * **Obligatorio es lo que el script siempre sabe y sin lo cual el asiento no
     * dice nada.** `backup_fingerprint` es opcional incluso en la vuelta atras,
     * y es una decision deliberada: calcular el `sha256` de un volcado de varios
     * gigabytes puede tardar minutos y puede fallar, y **un asiento sin huella
     * vale infinitamente mas que ningun asiento**. La regla es la de siempre en
     * este producto: el registro no se sacrifica por un dato accesorio.
     *
     * @var array<string, array{required: list<string>, optional: list<string>}>
     */
    private const array SPEC = [
        'system.updated' => [
            'required' => ['from_version', 'to_version', 'migrations_applied'],
            'optional' => ['chain_before', 'chain_after', 'backup_fingerprint', 'report_id'],
        ],
        'system.restored_from_backup' => [
            'required' => ['backup_file', 'backup_taken_at', 'failed_step', 'reason', 'from_version', 'to_version'],
            'optional' => ['backup_fingerprint', 'chain_before', 'report_id'],
        ],
    ];

    /** Version semantica del producto: `1.4.0`, `1.4.0-rc.1`. */
    private const string VERSION = '/^\d{1,4}\.\d{1,4}\.\d{1,4}(?:[-+][0-9A-Za-z.]{1,32})?$/';

    /** Huella `sha256` en minusculas. */
    private const string SHA256 = '/^[0-9a-f]{64}$/';

    /**
     * Nombre de fichero **sin ninguna ruta**.
     *
     * La ruta absoluta de la copia describe la topografia del servidor del
     * cliente, y el trail se exporta (RL-20) y viaja en el paquete de
     * diagnostico (ADR-020). El nombre basta para encontrarla; el directorio lo
     * sabe quien opera la maquina.
     */
    private const string FILE_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    /** Identificador del informe de la actualizacion (`update-AAAAMMDDTHHMMSSZ`). */
    private const string REPORT_ID = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    /** Instante en UTC con `Z` explicita: el trail no admite otra zona (regla dura 3). */
    private const string INSTANT_UTC = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/';

    /** Nombre de migracion de Laravel, tal cual esta en la tabla `migrations`. */
    private const string MIGRATION = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]{1,96}$/';

    /** Direccion de correo: la forma que jamas puede entrar en el trail. */
    private const string LOOKS_LIKE_EMAIL = '/[\p{L}0-9._%+-]+@[\p{L}0-9.-]+\.\p{L}{2,}/u';

    /** DNI (8 digitos y letra) o NIE (X/Y/Z, 7 digitos y letra). */
    private const string LOOKS_LIKE_NATIONAL_ID = '/(?<![0-9A-Za-z])(?:[0-9]{8}|[XYZxyz][0-9]{7})[- ]?[A-HJ-NP-TV-Za-hj-np-tv-z](?![0-9A-Za-z])/';

    /** Ruta absoluta de POSIX, de Windows o UNC. */
    private const string LOOKS_LIKE_ABSOLUTE_PATH = '#(?:^|[\s])(?:/|[A-Za-z]:[\\\\/]|[\\\\]{2})#';

    /**
     * Tope de la lista corta de migraciones. Por encima, el recuento dice lo
     * mismo y el payload no se convierte en un changelog dentro de la cadena.
     */
    private const int MIGRATIONS_LISTED_MAX = 25;

    private function __construct(
        public AuditAction $action,
        public AuditPayload $payload,
    ) {}

    /**
     * Valida los datos contra la lista cerrada de la accion y devuelve el
     * payload listo para encadenar.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function for(AuditAction $action, array $data): self
    {
        $spec = self::SPEC[$action->value]
            ?? throw InvalidSystemEventPayload::notASystemAction($action->value);

        $allowed = [...$spec['required'], ...$spec['optional']];

        foreach ($spec['required'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw InvalidSystemEventPayload::missingField($action->value, $field);
            }
        }

        foreach ($data as $field => $value) {
            if (! is_string($field) || ! in_array($field, $allowed, true)) {
                throw InvalidSystemEventPayload::unknownField($action->value, (string) $field, $allowed);
            }

            self::assertNoPersonalData($field, $value);
            self::assertShape($field, $value);
        }

        return new self($action, AuditPayload::of($data));
    }

    /**
     * Los ocho campos que son **una cadena con una forma**, con su expresion y
     * con lo que hay que decirle a quien se equivoque.
     *
     * Tabla y no ramas de un `match` por dos motivos: la mitad de los campos
     * comparten expresion —tres son `sha256`, dos son version— y separarlos en
     * ramas invitaba a que manana dos huellas se validaran distinto. Los tres
     * campos que **no** son una cadena con una forma no caben aqui y se resuelven
     * aparte en {@see self::assertShape()}.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array SHAPES = [
        'from_version' => [self::VERSION, 'una version semantica'],
        'to_version' => [self::VERSION, 'una version semantica'],
        'chain_before' => [self::SHA256, 'un sha256 en minusculas'],
        'chain_after' => [self::SHA256, 'un sha256 en minusculas'],
        'backup_fingerprint' => [self::SHA256, 'un sha256 en minusculas'],
        'backup_file' => [self::FILE_NAME, 'un nombre de fichero sin ruta'],
        'report_id' => [self::REPORT_ID, 'el identificador del informe de actualizacion'],
        'backup_taken_at' => [self::INSTANT_UTC, 'un instante ISO-8601 en UTC terminado en Z'],
    ];

    /**
     * La forma que debe tener cada clave admitida.
     *
     * Los tres campos que no estan en {@see self::SHAPES} son los que no son una
     * cadena con una forma: dos son vocabularios cerrados —y la lista de valores
     * la da el propio enum, para que anadir un motivo no obligue a tocar aqui— y
     * `migrations_applied` puede ser un numero o una lista.
     */
    private static function assertShape(string $field, mixed $value): void
    {
        if (isset(self::SHAPES[$field])) {
            [$pattern, $expected] = self::SHAPES[$field];

            self::assertMatches($field, $value, $pattern, $expected);

            return;
        }

        match ($field) {
            'failed_step' => self::assertEnum($field, $value, array_map(
                static fn (SystemUpdateStep $step): string => $step->value,
                SystemUpdateStep::cases(),
            )),
            'reason' => self::assertEnum($field, $value, array_map(
                static fn (SystemRestoreReason $reason): string => $reason->value,
                SystemRestoreReason::cases(),
            )),
            'migrations_applied' => self::assertMigrations($value),
            default => null,
        };
    }

    private static function assertMatches(string $field, mixed $value, string $pattern, string $expected): void
    {
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw InvalidSystemEventPayload::malformedField($field, $expected);
        }
    }

    /**
     * @param  list<string>  $values
     */
    private static function assertEnum(string $field, mixed $value, array $values): void
    {
        if (! is_string($value) || ! in_array($value, $values, true)) {
            throw InvalidSystemEventPayload::malformedField($field, 'uno de: '.implode(', ', $values));
        }
    }

    /**
     * `migrations_applied` admite **un recuento o una lista corta**, y las dos
     * cosas responden a la misma pregunta con distinto detalle.
     *
     * Se admiten las dos porque el script no siempre puede nombrarlas: cuando la
     * actualizacion salta varias versiones seguidas, la lista es de decenas y el
     * recuento es lo unico util. Cero es un valor legitimo —una version sin
     * migraciones— y por eso no se rechaza.
     */
    private static function assertMigrations(mixed $value): void
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw InvalidSystemEventPayload::malformedField('migrations_applied', 'un recuento no negativo');
            }

            return;
        }

        if (! is_array($value) || ! array_is_list($value) || \count($value) > self::MIGRATIONS_LISTED_MAX) {
            throw InvalidSystemEventPayload::malformedField(
                'migrations_applied',
                'un recuento entero o una lista de como mucho '.self::MIGRATIONS_LISTED_MAX.' nombres de migracion',
            );
        }

        foreach ($value as $migration) {
            self::assertMatches('migrations_applied', $migration, self::MIGRATION, 'nombres de migracion de Laravel');
        }
    }

    /**
     * Rechazo por **forma**, no por origen.
     *
     * No se comprueba de donde viene el valor: se comprueba que no se parezca a
     * un dato personal ni a la topografia del servidor. Es deliberadamente tosco
     * y deliberadamente pesimista —un nombre de fichero con una arroba dentro se
     * rechaza aunque sea inocente—, porque el coste de un rechazo es que la
     * actualizacion lo apunte en su informe, y el de un falso negativo es un
     * correo o un DNI grabado para siempre en una tabla solo-append que ademas
     * se exporta.
     *
     * Se comprueba **antes** que la forma del campo: si alguien mete un correo
     * donde va una version, el mensaje tiene que decir «esto parece un correo»
     * y no «esto no es una version».
     */
    private static function assertNoPersonalData(string $field, mixed $value): void
    {
        $items = is_array($value) ? $value : [$value];

        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }

            if (preg_match(self::LOOKS_LIKE_EMAIL, $item) === 1) {
                throw InvalidSystemEventPayload::looksLikePersonalData($field, 'una direccion de correo');
            }

            if (preg_match(self::LOOKS_LIKE_NATIONAL_ID, $item) === 1) {
                throw InvalidSystemEventPayload::looksLikePersonalData($field, 'un DNI o un NIE');
            }

            if (preg_match(self::LOOKS_LIKE_ABSOLUTE_PATH, $item) === 1) {
                throw InvalidSystemEventPayload::looksLikePersonalData($field, 'una ruta absoluta del servidor');
            }
        }
    }
}
