<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Una linea del fichero **ya normalizada**, antes de decidir que hacer con ella
 * (**RF-GP-05**).
 *
 * ## Que hay aqui y que no
 *
 * Los siete campos que el importador reconoce. **No hay `employee_code`**: es
 * opaco y lo genera el servidor (doc 01 §5.5), asi que leerlo del fichero seria
 * meter en una tarjeta impresa un codigo con significado —el numero de nomina
 * del sistema anterior, casi siempre— y ademas secuencial.
 *
 * ## `nationalId` viaja en claro **solo hasta la insercion**
 *
 * Y ahi muere: el repositorio lo convierte en `digest(?, 'sha256')` dentro de la
 * propia sentencia (RL-08). No existe ninguna columna, ningun atributo de modelo
 * y ningun registro con el documento legible. Este objeto es lo mas lejos que
 * llega, y por eso no se serializa nunca.
 *
 * ## El documento se normaliza AQUI, en el constructor, y en ningun otro sitio
 *
 * Mayusculas, sin espacios y sin guiones. **Es una correccion de la revision de
 * la 5.5**, y el fallo que arregla es de los que no se ven hasta que duelen: la
 * comparacion dentro del fichero era insensible a mayusculas —`mb_strtolower` en
 * {@see self::identityKey()}— y la comparacion contra la base era sensible,
 * porque `digest('12345678z')` y `digest('12345678Z')` son dos huellas
 * distintas. Un alta hecha con el documento en minuscula y una reimportacion en
 * mayuscula **creaban dos fichas de la misma persona**, con su registro horario
 * partido en dos (regla dura 5).
 *
 * Se normaliza en el constructor —y no en cada consumidor— para que la clave de
 * identidad, la busqueda contra la base y el valor que se inserta sean
 * **literalmente el mismo**. Con la normalizacion repartida, bastaba con
 * olvidarla en uno de los tres para reabrir el mismo agujero.
 *
 * Los guiones y los espacios se quitan porque `12345678-Z`, `12345678 Z` y
 * `12345678Z` son el mismo documento escrito por tres personas distintas, y en
 * un CSV exportado a mano aparecen los tres.
 *
 * ## `identityKey` es lo que hace idempotente la reimportacion
 *
 * El documento si viene, y si no el correo, **normalizados**. Es la clave con la
 * que se busca a la persona en la base y con la que se detecta un duplicado
 * dentro del propio fichero. Sin ella, subir dos veces el mismo CSV crearia la
 * plantilla dos veces.
 */
final readonly class ImportedEmployee
{
    /**
     * NO se promociona, al contrario que los demas: se asigna en el cuerpo con
     * el valor ya normalizado. Una propiedad promocionada se inicializa con el
     * argumento tal cual llega, y en una clase `readonly` no se puede volver a
     * escribir despues.
     */
    public ?string $nationalId;

    public function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $email,
        ?string $nationalId,
        public ?string $department,
        public ?string $hiredAt,
        public ?string $locale,
    ) {
        $this->nationalId = self::normaliseNationalId($nationalId);
    }

    /**
     * La forma canonica de un documento de identidad.
     *
     * **Publica porque tiene que poder llamarse desde fuera**: el adaptador que
     * consulta la base compara con `digest(?, 'sha256')` y necesita hashear
     * exactamente esta misma cadena. Dos normalizaciones distintas —una aqui y
     * otra alli— serian el mismo fallo que esta funcion existe para cerrar.
     */
    public static function normaliseNationalId(?string $nationalId): ?string
    {
        if ($nationalId === null) {
            return null;
        }

        $normalised = str_replace([' ', '-', '.', "\u{00A0}"], '', mb_strtoupper(trim($nationalId)));

        return $normalised === '' ? null : $normalised;
    }

    /**
     * Con que se reconoce a esta persona entre dos importaciones, o `null` si no
     * hay forma.
     *
     * **El documento manda sobre el correo**: el correo de una persona cambia
     * —se casa, cambia de dominio, deja de tener— y su documento no. Si mandara
     * el correo, cambiarlo en el fichero crearia un alta nueva en lugar de
     * actualizar la ficha.
     *
     * El prefijo (`nid:`, `mail:`) evita que un documento que se parezca a un
     * correo colisione con un correo, que es un caso absurdo pero gratis de
     * cerrar.
     */
    public function identityKey(): ?string
    {
        if ($this->nationalId !== null) {
            // Ya viene normalizado del constructor: no se vuelve a tocar aqui,
            // porque una segunda normalizacion es una segunda oportunidad de que
            // las dos diverjan.
            return 'nid:'.$this->nationalId;
        }

        return $this->email === null ? null : 'mail:'.self::normaliseEmail($this->email);
    }

    /**
     * El correo en la forma con la que se compara: minusculas y sin espacios.
     *
     * La columna es `citext`, asi que la base ya compara sin distinguir
     * mayusculas; esto es para las comparaciones **dentro del fichero**, que no
     * pasan por la base. Que exista una sola funcion evita que las dos se
     * separen.
     */
    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Como se llama, para que el informe pueda decir de quien habla.
     *
     * **Va en la respuesta de la API y en ningun sitio mas** (regla dura 21): no
     * entra en `error_events`, ni en el log tecnico, ni en el asiento de
     * auditoria del alta masiva. «La linea 14 se rechaza» sin nombre no sirve
     * para arreglar nada, y quien lee el informe es RRHH con el fichero delante.
     */
    public function label(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }
}
