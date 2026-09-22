<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Categoria de una ausencia (**RF-GP-04**, doc 01 §5.5, doc 05 §5.5).
 *
 * ## Catalogo cerrado, y por que no contradice ADR-017
 *
 * Los tipos **no son configurables**. ADR-017 exige que toda diferencia entre
 * clientes sea configuracion, y esto no es una diferencia entre clientes: es el
 * vocabulario del producto. Un catalogo abierto haria que el informe de
 * absentismo de un hotel no se pudiera comparar con el de otro —ni con el suyo
 * del año pasado, en cuanto alguien renombrara una categoria—, que es
 * exactamente la razon por la que el catalogo de tipos de incidencia tambien
 * esta cerrado.
 *
 * ## En ingles, al contrario que {@see ScheduleType}
 *
 * Aquel es el catalogo **literal** que el doc 01 §5.5 fija para su columna, y
 * por eso conserva el castellano. Este no lo fija ningun documento: el doc 05
 * §5.5 nombra las categorias en prosa comercial —«vacaciones, baja, permiso»—,
 * que no es lo mismo que fijar el valor de una columna. Asi que vale la regla
 * general del §3.5 —los identificadores en ingles— y el castellano vive donde
 * le corresponde: en `i18n` para quien lo lee y en {@see self::fromImportLabel()}
 * para quien rellena un fichero.
 *
 * ## `other` existe y exige nota
 *
 * Es el permiso que no es ninguno de los tres anteriores. Sin texto no describe
 * nada, y una categoria que no describe nada acaba usandose para todo: en seis
 * meses la mitad del cuadro seria `other` y el informe no diria nada. Por eso
 * {@see self::requiresNote()} y por eso {@see Absence} lo hace cumplir.
 */
enum AbsenceType: string
{
    /** Vacaciones. */
    case Vacation = 'vacation';

    /**
     * Baja medica.
     *
     * **Es dato de salud** (regla dura 21). El tipo si entra en `audit_log`
     * —sin el, el asiento no describe el hecho, y `audit_log` es el registro
     * legal con acceso restringido— pero **no** en logs tecnicos, ni en mensajes
     * de excepcion, ni en `error_events`, que es lo que viaja al fabricante
     * dentro del paquete de diagnostico.
     */
    case SickLeave = 'sick_leave';

    /** Permiso retribuido o no retribuido de los tipificados. */
    case Leave = 'leave';

    /** El permiso que no es ninguno de los anteriores. **Exige nota**. */
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    /**
     * El tipo que corresponde a una celda de un fichero de importacion, o
     * `null` si no se reconoce (**RF-GP-04**, decision 5 de la ficha 3.10).
     *
     * ## Por que los alias castellanos viven aqui y solo para la importacion
     *
     * Quien rellena un cuadrante de vacaciones en una hoja de calculo escribe
     * «vacaciones» y «baja», no `vacation` ni `sick_leave`. Exigirle el
     * identificador ingles convertiria el fichero en un formulario del producto
     * en vez de en lo que el hotel ya tiene.
     *
     * **No es una traduccion del enum.** La API habla siempre en ingles y el
     * panel traduce con `i18n`: si estos alias se aceptaran en `POST /absences`,
     * el producto tendria dos vocabularios para lo mismo y el cliente TypeScript
     * generado del contrato no conoceria uno de ellos.
     *
     * **Los alias de columna SI son configuracion** (`config/workforce.php`,
     * regla dura 13) y estos **no**, y la asimetria es deliberada: el nombre de
     * una columna depende del sistema del que salga el fichero, pero el conjunto
     * de categorias es el catalogo cerrado del producto. Un alias propio para un
     * valor seria una categoria nueva por la puerta de atras.
     *
     * La comparacion es tolerante con la misma norma que {@see ImportColumnMap}:
     * minusculas, sin acentos y con los separadores unificados, porque una hoja
     * de calculo trae «Vacaciones», «BAJA MEDICA» y «baja_medica» indistintamente.
     */
    public static function fromImportLabel(string $label): ?self
    {
        return self::byImportLabel()[ImportColumnMap::normalise($label)] ?? null;
    }

    /**
     * Etiqueta normalizada -> categoria.
     *
     * **Una tabla y no un `match` con listas de valores**, y no es estetica: el
     * `match` era una decision por cada alias —trece— y pasaba del limite de
     * complejidad ciclomatica del §3.5. Escrito asi, añadir un alias no añade
     * una rama, y la lista se lee de un vistazo.
     *
     * @return array<string, self>
     */
    private static function byImportLabel(): array
    {
        return [
            'vacation' => self::Vacation,
            'vacaciones' => self::Vacation,
            'vacacion' => self::Vacation,

            'sick_leave' => self::SickLeave,
            'baja' => self::SickLeave,
            'baja_medica' => self::SickLeave,
            // `IT` es como la llama media hosteleria en las hojas de calculo:
            // «incapacidad temporal», que es el nombre administrativo de la baja.
            'it' => self::SickLeave,

            'leave' => self::Leave,
            'permiso' => self::Leave,

            'other' => self::Other,
            'otro' => self::Other,
            'otros' => self::Other,
        ];
    }

    /**
     * Si esta categoria necesita que se explique con texto.
     *
     * Solo `other`: los tres primeros se explican solos y pedir nota para unas
     * vacaciones seria burocracia inventada.
     */
    public function requiresNote(): bool
    {
        return $this === self::Other;
    }
}
