<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Exception\UnknownSettingKey;

/**
 * El catalogo de claves de configuracion de la instalacion (RF-PD-01, ADR-017).
 *
 * **Este enum es el catalogo.** No hay una clase `SettingCatalog` aparte porque
 * no habria nada dentro que no este ya aqui: el conjunto de claves es cerrado y
 * se conoce al compilar, y `SettingKey::cases()` lo recorre entero. Que sea un
 * tipo y no una cadena suelta es lo que impide que un modulo pida
 * `'ATTENDANC_MAX_SHIFT_HOURS'` y reciba el valor de serie durante meses sin que
 * nadie lo note.
 *
 * ## La convencion de nombres
 *
 * `MODULO_CONCEPTO` en mayusculas, **identica a la variable de entorno del Anexo
 * B del doc 02 cuando la propiedad tiene las dos caras**. No es coincidencia: la
 * variable de entorno es el valor de arranque con el que el instalador siembra
 * la primera fila, y la fila es la que manda a partir de ahi. Llamarlas igual es
 * lo que hace legible esa relacion y lo que permite el contraste cruzado que
 * pide la tarea 5.11 («cada parametro del Anexo B y cada clave de
 * `installation_settings` documentados»).
 *
 * Las cuatro claves `ATTENDANCE_*` **se conservan tal como las sembro la tarea
 * 1.3**: renombrarlas seria una migracion de datos a cambio de nada. Son ademas
 * identificadores tecnicos internos, y el doc 02 §5.8 dice que esos no se
 * renombran.
 *
 * ## Que no esta aqui, y por que
 *
 * - **Los umbrales legales** —descanso minimo, jornada maxima, pausas,
 *   retencion— viven en `compliance_profiles` y llegan por
 *   `CompliancePolicyProvider`. Un umbral legal lo fija la jurisdiccion; uno
 *   operativo lo fija el hotel (doc 01 §4, nota sobre RN-08 y RN-16).
 * - **Las funcionalidades activas** las codifica `features` de la licencia
 *   (ADR-018, ADR-023) y no una clave editable por el administrador: si el
 *   cliente pudiera encenderlas desde el panel, la licencia no limitaria nada.
 * - **Lo que es del despliegue** —rutas, credenciales, endpoints— sigue siendo
 *   variable de entorno: no tiene sentido editarlo sin reiniciar y no se audita
 *   (ADR-017, alternativas descartadas).
 */
enum SettingKey: string
{
    /** RN-08: a partir de cuantas horas un tramo es anomalo. **Nunca se cierra solo**. */
    case ATTENDANCE_MAX_SHIFT_HOURS = 'ATTENDANCE_MAX_SHIFT_HOURS';

    /** RF-AT-06: ventana de gracia anti-rebote de un mismo empleado. */
    case ATTENDANCE_DEBOUNCE_SECONDS = 'ATTENDANCE_DEBOUNCE_SECONDS';

    /** RF-AT-10: desfase tolerado entre el reloj del quiosco y el del servidor. Nunca rechaza el fichaje. */
    case ATTENDANCE_MAX_CLOCK_SKEW_MINUTES = 'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES';

    /** RN-16: transito minimo creible entre dos quioscos del centro. */
    case ATTENDANCE_MIN_TRANSIT_SECONDS = 'ATTENDANCE_MIN_TRANSIT_SECONDS';

    /**
     * RF-PD-08: nombre de la aplicacion.
     *
     * **Hoy se guarda y se audita, y todavia no se pinta.** Las tres SPA y la
     * cabecera de los PDF siguen leyendo la marca del entorno; la tarea 5.8 es
     * la que las pasa al puerto `BrandingProvider`, que ya lee esta clave.
     */
    case BRANDING_APP_NAME = 'BRANDING_APP_NAME';

    /**
     * RF-PD-08: ruta del logotipo en el servidor del cliente.
     *
     * Del sistema de ficheros y no una URL: el PDF se genera en un Chromium sin
     * salida a internet (ADR-016). **Que el fichero exista no se comprueba
     * todavia**: lo hara `doctor` en la tarea 5.9. Quien dibuja se lo salta y
     * sigue, porque nadie se queda sin fichar porque falte una imagen.
     */
    case BRANDING_LOGO_PATH = 'BRANDING_LOGO_PATH';

    /**
     * RF-PD-08: color de acento, en notacion CSS.
     *
     * Igual que el nombre: hoy se guarda y se audita. Su aplicacion a la
     * interfaz y a la tarjeta impresa llega con la tarea 5.8.
     */
    case BRANDING_ACCENT_COLOR = 'BRANDING_ACCENT_COLOR';

    /** Idioma con el que se sirven las SPA y los documentos cuando nadie elige otro. */
    case LOCALE_DEFAULT = 'LOCALE_DEFAULT';

    /** Idiomas que la instalacion ofrece. El producto se entrega con dos (DoD §10.3: textos en español e ingles). */
    case LOCALE_AVAILABLE = 'LOCALE_AVAILABLE';

    /**
     * Los idiomas que el producto trae traducidos.
     *
     * No es configuracion del cliente: es lo que hay en `lang/` y en los `i18n`
     * de las tres SPA. Ofrecer uno que no existe daria una interfaz a medio
     * traducir, asi que el conjunto es cerrado y crece cuando crece la
     * traduccion, no cuando lo pide un cliente.
     */
    private const array SHIPPED_LOCALES = ['es', 'en'];

    /**
     * La clave, o {@see UnknownSettingKey}.
     *
     * Es el unico punto por el que una cadena de fuera —el cuerpo de un `PATCH`,
     * un argumento de consola— se convierte en clave. Que falle aqui y no mas
     * adentro es lo que hace que el 422 diga cual es la clave mala.
     */
    public static function fromString(string $key): self
    {
        return self::tryFrom($key) ?? throw new UnknownSettingKey($key);
    }

    /** Que puede valer esta clave, cuanto vale de serie y que es cuando cambia. */
    public function definition(): SettingDefinition
    {
        return self::catalog()[$this->value] ?? throw new UnknownSettingKey($this->value);
    }

    /**
     * El catalogo, literal.
     *
     * Un array y no un `match`: con un brazo por clave, la complejidad
     * ciclomatica del metodo crece con el catalogo y choca con el limite de 10
     * del §3.5 en la decima clave. Un literal no tiene puntos de decision, y
     * `SettingCatalogTest` comprueba que no falte ninguno.
     *
     * @return array<string, SettingDefinition>
     */
    private static function catalog(): array
    {
        return [
            // 12 h, el valor del Anexo B y el que sembro la tarea 1.3. En horas
            // y no en minutos porque asi lo dice el negocio; la conversion la
            // hace el adaptador, en el borde.
            self::ATTENDANCE_MAX_SHIFT_HOURS->value => SettingDefinition::integer(
                12, 1, 24, SettingImpact::COMPLIANCE_REVIEW,
            ),
            // Cero es legitimo: apaga el anti-rebote. El maximo es una hora,
            // porque una ventana mayor se comeria un fichaje real.
            self::ATTENDANCE_DEBOUNCE_SECONDS->value => SettingDefinition::integer(
                60, 0, 3600, SettingImpact::WORKED_HOURS,
            ),
            // Minimo 1: `OperationalSettings` rechaza el cero, porque un cero
            // marcaria incidencia ante un segundo de deriva en cada escaneo.
            self::ATTENDANCE_MAX_CLOCK_SKEW_MINUTES->value => SettingDefinition::integer(
                15, 1, 1440, SettingImpact::COMPLIANCE_REVIEW,
            ),
            // Cero es legitimo: dos tablets contiguas en la misma puerta.
            self::ATTENDANCE_MIN_TRANSIT_SECONDS->value => SettingDefinition::integer(
                120, 0, 3600, SettingImpact::COMPLIANCE_REVIEW,
            ),
            // El valor por defecto **es** el producto (tarea 5.8, paso 8): sin
            // configurar nada se ve la marca del fabricante, nunca la de otro
            // cliente. 60 caracteres es lo que cabe en la cabecera de la tarjeta.
            self::BRANDING_APP_NAME->value => SettingDefinition::text(
                'KronoQR', 60, SettingImpact::PRESENTATION,
            ),
            // Vacia significa «el logotipo del producto». Ruta del sistema de
            // ficheros y no URL: el PDF se genera en un Chromium sin salida a
            // internet (ADR-016). Que el fichero exista lo comprueba el
            // adaptador, no el dominio.
            self::BRANDING_LOGO_PATH->value => SettingDefinition::optionalText(
                '', 512, SettingImpact::PRESENTATION,
            ),
            // El gris del sistema visual del doc 06. Se valida la forma para que
            // un color mal escrito de un 422 y no una interfaz sin estilo.
            self::BRANDING_ACCENT_COLOR->value => SettingDefinition::text(
                '#111827', 7, SettingImpact::PRESENTATION, '/^#[0-9a-fA-F]{6}$/',
            ),
            self::LOCALE_DEFAULT->value => SettingDefinition::choice(
                'es', self::SHIPPED_LOCALES, SettingImpact::PRESENTATION,
            ),
            self::LOCALE_AVAILABLE->value => SettingDefinition::choiceList(
                self::SHIPPED_LOCALES, self::SHIPPED_LOCALES, SettingImpact::PRESENTATION,
            ),
        ];
    }
}
