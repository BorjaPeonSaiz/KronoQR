# ADR-029 — La configuración vive en el entorno del contenedor, no en un `.env` del backend

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 14 de agosto de 2026 |
| **Decide** | `devops-observabilidad` · decisión tomada al ejecutar la tarea 0.2 |
| **Afecta a** | Tareas 0.1, 0.2 y 5.4 · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §7.7 y Anexo B · Reglas duras 3 y 13 de `CLAUDE.md` |
| **Requisitos** | RF-PD-01, RF-PD-02, RS-08, RNF-M-06 |

> Los ADR-001 a ADR-020 provienen de la tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4. Este, como los ADR-021 a ADR-028, nace de una decisión tomada al desarrollar el plan de implementación: quedó implementada en la tarea 0.2 y sin documentar hasta ahora.

## Contexto

El entorno de desarrollo se levanta con Docker Compose y **un `.env` canónico en la raíz del repositorio**, que Compose inyecta en los contenedores mediante `env_file` (tarea 0.1). Esa es la fuente de configuración: la misma para todos los servicios —`app`, `horizon`, `reverb`, `scheduler`—, con un solo fichero que revisar y un solo sitio donde poner un secreto.

Laravel, en cambio, espera encontrar un `.env` **junto a `artisan`**. Al instalar la aplicación en `backend/` (tarea 0.2) apareció el conflicto en su forma más molesta: sin ese fichero, `php artisan test` **avisa en cada prueba** de que no puede leerlo. Un aviso repetido en cada ejecución es ruido que acaba escondiendo un fallo real, así que ignorarlo no era una opción.

La salida intuitiva —copiar la configuración a `backend/.env`— crea dos fuentes de verdad para el mismo valor. Y peor: las variables ya presentes en el entorno **ganan siempre** sobre las del fichero, así que lo que se escriba ahí se ignorará en unos casos y se aplicará en otros, según cómo se arranque el proceso. Es el peor fallo posible en configuración, porque no falla: funciona mal en silencio.

## Decisión

**La configuración del backend vive en el entorno del contenedor. El `.env` canónico está en la raíz del repositorio y Compose lo inyecta; `backend/.env` existe vacío y comentado, y no configura nada.**

- Lo crea `infra/docker/php/entrypoint.sh` **de forma idempotente**: si ya existe, no se toca. Solo se crea cuando existe `backend/artisan`, es decir, cuando hay aplicación que lo necesite.
- Su contenido es un comentario que dice exactamente por qué está vacío y por qué no hay que rellenarlo. **Existe para que no haya dos fuentes de verdad**, no para configurar.
- Ninguno de los dos ficheros se versiona: `.gitignore` ignora `.env` en cualquier nivel, y lo versionado es `.env.example` (§7.7, RS-08).
- En la instalación del cliente aplica el mismo modelo: `install.sh` **genera los secretos en el servidor** y los deja en el `.env` que Compose inyecta (tarea 5.4). El fabricante no los transmite ni los conoce.

### Dos consecuencias prácticas ya vividas

Ninguna es teórica: las dos salieron de la tarea 0.2.

1. **`php artisan key:generate` se usa con `--show`** y el valor se pega en el `.env` de la raíz. Sin `--show`, el comando escribiría en `backend/.env`, que es justo el fichero que no configura nada: `APP_KEY` acabaría en el sitio equivocado y el que manda seguiría vacío.
2. **Una variable vacía nunca lleva comentario en su misma línea.** Docker Compose no interpreta el `#` de final de línea como comentario en un `env_file`: **le asigna el texto entero como valor**. Una línea como `MAIL_PASSWORD=            # vacio en desarrollo` entrega una contraseña que es un comentario. Es un fallo real, encontrado y corregido en la 0.2, y se manifiesta como un error de autenticación que no se parece en nada a su causa. Los comentarios van **en la línea anterior**.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Darle configuración real a `backend/.env`** | Dos fuentes de verdad para el mismo valor, con una regla de precedencia que casi nadie tiene presente: el entorno gana. El resultado es un valor que se aplica o se ignora según cómo se arranque el proceso, y una tarde perdida buscando por qué la conexión a base de datos es distinta en `artisan` y en la web |
| **`loadEnvironmentFrom()` con otro nombre de fichero** | Evita el aviso y rompe el ecosistema: `key:generate`, `env:encrypt` y cualquier receta de la documentación de Laravel asumen `.env`. Y rompe sobre todo al siguiente mantenedor, que buscará un fichero que se llama como todos los demás proyectos y no lo encontrará |
| **Enlace simbólico de `backend/.env` al `.env` de la raíz** | Funciona en Linux y se comporta de forma distinta en el bind mount de Windows, que es la estación de desarrollo. Y reintroduce la posibilidad de escribir configuración desde el contenedor sobre el fichero canónico |
| **Un `.env` por servicio** | Cuatro ficheros que mantener sincronizados para un mismo despliegue. La primera divergencia produce un worker que escribe en otra base de datos que la API |
| **No crear el fichero y convivir con el aviso** | Un aviso en cada prueba entrena a ignorar los avisos. El coste de una excepción documentada es menor que el de una suite ruidosa |
| **Mover el `.env` canónico a `backend/`** | La configuración no es solo del backend: Compose la necesita para PostgreSQL, Redis, Grafana y los tres servicios de frontend. El fichero pertenece al despliegue, no a la aplicación |

## Consecuencias

- **Un solo sitio donde mirar y donde poner un secreto**, coherente con §7.7 y con RS-08. La comprobación de qué configuración tiene una instalación es leer un fichero.
- **Todo parámetro nuevo se documenta en `.env.example`** —versionado y comentado— y en el Anexo B del documento 02. Un parámetro que solo existe en la máquina de alguien no existe.
- **`backend/.env` es un fichero vacío que hay que saber leer.** Su comentario es la única defensa contra que alguien lo rellene con buena intención; por eso está redactado como advertencia y no como nota.
- **La creación es idempotente y condicionada a que exista `artisan`**, de modo que el entrypoint no crea basura antes de que haya aplicación, ni pisa un fichero existente.
- **En producción no cambia el modelo**, lo que hace que el entorno de desarrollo se parezca al del cliente: mismo mecanismo, distinto origen de los valores.
- **Las variables de entorno son el vehículo de las reglas duras 3 y 13**: `APP_TIMEZONE=UTC` llega por aquí, y la diferencia entre clientes es dato —configuración y base de datos—, nunca código ([ADR-017](ADR-017-toda-diferencia-entre-clientes-es-configuracion.md)).

## Verificación

- `backend/.env` está vacío de configuración: no contiene ninguna línea que no sea comentario.
- Ejecutar el entrypoint dos veces no modifica un `backend/.env` existente (idempotencia).
- `php artisan test` no emite el aviso de fichero de entorno ausente.
- Comprobación sobre `.env` y `.env.example`: **ninguna línea con valor vacío lleva un comentario a su derecha**. Es la que evita que vuelva el fallo de la tarea 0.2, y se ata a un `grep` en la CI.
- `git ls-files` no incluye ningún `.env`, solo `.env.example` (RS-08).
- Prueba de instalación limpia (RQ-11): `install.sh` genera los secretos en el servidor y el sistema arranca sin que ningún `.env` del repositorio contenga valores reales.
