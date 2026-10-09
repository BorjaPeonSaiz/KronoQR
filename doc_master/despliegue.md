# Despliegue en funcionamiento

## 1. Dónde está

| | |
| --- | --- |
| Dirección | <https://kronoqr.kodigolab.es> |
| Estado y versión | <https://kronoqr.kodigolab.es/api/v1/health> (responde `{"status":"ok","version":"2.2.0",…}`) |
| Panel de gestión | <https://kronoqr.kodigolab.es/admin/> |
| Quiosco de fichaje | <https://kronoqr.kodigolab.es/kiosk/> |
| Portal del empleado | <https://kronoqr.kodigolab.es/portal/> |

Es una instalación de demostración con datos ficticios. No contiene datos de ningún empleado real.

> **Versión entregada y versión desplegada.** La versión que se entrega es la **2.2.0**, publicada el 9 de octubre de 2026 ([release](https://github.com/BorjaPeonSaiz/KronoQR/releases/tag/v2.2.0)). La demostración **está en la 2.2.0**: se instaló con la 2.1.0 y se actualizó el 9 de octubre de 2026 con el procedimiento del §5. `/api/v1/health` dice siempre cuál está en marcha.

## 2. Cómo está desplegado

No hay un «entorno de producción» distinto del que recibe un cliente: el despliegue se hizo **con el mismo paquete `kronoqr-2.1.0.tar.gz` y el mismo `install.sh`** que se entrega a un hotel, en un servidor Linux con Docker y Docker Compose v2, y se actualizó a la 2.2.0 con `kronoqr-2.2.0.tar.gz` y `update.sh`.

- **Imágenes**: las publicadas por `release.yml` en GHCR al etiquetar `v2.2.0`, fijadas por digest (`php`, `nginx`, `postgres`), más `redis:7-alpine`.
- **Servicios**: `nginx` (TLS, estáticos y rate limiting), `app` (PHP‑FPM), `horizon` (colas), `scheduler` (tareas programadas), `reverb` (WebSocket), `postgres` y `redis`. Es la pila de [`infra/compose.prod.yaml`](../infra/compose.prod.yaml).
- **Secretos**: los generó el instalador en el propio servidor (`APP_KEY`, claves de firma del QR, clave de cifrado de copias, contraseñas de los tres roles de base de datos). Ninguno está en el repositorio.
- **Puesta en marcha**: el primer acceso al panel abrió el asistente, que creó la organización, el centro con su zona horaria, los departamentos, el perfil de cumplimiento (español, 4 años de retención) y el primer administrador.
- **Licencia**: activada con una licencia firmada por el emisor de [`tools/license-issuer/`](../tools/license-issuer/); `health` la reporta como `valid`. Sin ella el sistema ficharía igual (ADR‑019).
- **Copias**: `backup.sh` diario, cifrado y verificado, como en cualquier instalación.

### Qué está abierto a internet y por qué

Por defecto el producto restringe el portal del empleado a la red interna del hotel (RF‑ID‑08) y espera los quioscos en una VLAN propia. En esta instalación el propietario decidió abrir **panel, quiosco y portal** a internet (`PORTAL_INTERNAL_CIDR=0.0.0.0/0`) para poder evaluar el producto desde cualquier sitio. Es una decisión que el producto permite y documenta ([ADR‑050](../docs/adr/ADR-050-portal-accesible-desde-internet.md)); un hotel real no lo haría con el quiosco.

Consecuencia práctica: el quiosco abierto en un navegador desde fuera de la VLAN cae al límite de tasa «por origen» (30 peticiones por minuto). Para una demostración es más que suficiente.

La 2.2.0 añade las defensas que ADR‑050 exige a un portal expuesto, y que la demostración ya tiene: bloqueo por origen (20 accesos fallidos en 15 minutos bloquean esa conexión durante una hora), PIN configurable de 8 cifras y la variable `ADMIN_INTERNAL_CIDR` para cerrar el panel a una red. `product:doctor` avisa de un portal expuesto con PIN corto.

## 3. Credenciales de prueba

| Aplicación | Acceso |
| --- | --- |
| **Panel** (<https://kronoqr.kodigolab.es/admin/>) | Usuario `bpeonsai@gmail.com` · la contraseña y el teléfono del alumno para el código del segundo factor se facilitan en el formulario de entrega del TFM, no aquí |
| Portal del empleado | Código de empleado y PIN de cualquier empleado dado de alta desde el panel (§4, paso 2) |
| Quiosco | Sin usuario: muestra un código de 6 dígitos que se teclea en el panel (§4, paso 4) |

## 4. Recorrido guiado

El producto se entiende mejor recorriendo el ciclo completo. Cuesta unos diez minutos y solo hace falta un navegador (el quiosco funciona en el navegador de un ordenador; la cámara es opcional, porque también se ficha con código y PIN).

### Paso 1 · Entrar en el panel

<https://kronoqr.kodigolab.es/admin/> con el usuario de la tabla y la contraseña del formulario de entrega.

**Segundo factor.** La cuenta tiene el 2FA obligatorio de los roles de gestión (RS‑06): tras la contraseña, el panel pide un código de 6 dígitos que genera la aplicación de autenticación del móvil del alumno y que cambia cada 30 segundos. En el momento de entrar, **solicita el código por teléfono al alumno** (su número va en el formulario de entrega junto al usuario y la contraseña); tienes medio minuto para teclearlo, y si caduca basta con pedir el siguiente.

La pantalla inicial es **Presencia**: quién está fichado ahora mismo, en tiempo real.

### Paso 2 · Dar de alta a una persona

*Plantilla → «Dar de alta»*. Nombre, apellidos, departamento, fecha de alta y horas contratadas. No hace falta correo.

Al guardar, el panel muestra **una sola vez** el **código de empleado** y el **PIN**. Anótalos: son lo que esa persona usa en el portal y como respaldo en el quiosco. Si se pierden, la ficha permite reemitir el PIN.

### Paso 3 · Emitir e imprimir su tarjeta

*Credenciales*: «Emitir credencial» y después «Imprimir la tarjeta» (el QR se acuña al generar el PDF; no hay reimpresión: si se pierde el PDF se revoca y se emite otra). Se descarga un PDF con la tarjeta y su QR. Para la demostración no hace falta imprimirla: basta con abrir el PDF en pantalla (en el móvil, por ejemplo) y mostrárselo a la cámara del quiosco.

### Paso 4 · Emparejar un quiosco en el navegador

Abre <https://kronoqr.kodigolab.es/kiosk/> en otra pestaña o en otro dispositivo. Una tablet sin vincular muestra un **código de emparejamiento** de 6 dígitos, de un solo uso y con 10 minutos de vida.

En el panel, *Quioscos → «Vincular quiosco»*: teclea ese código y ponle un nombre («Demo»). A partir de ahí la pestaña es un quiosco: recibe su token de dispositivo, descarga el padrón cifrado y queda lista para fichar, también sin red. El código no lleva nada personal y el secreto del token solo lo tiene la pestaña que lo pidió.

### Paso 5 · Fichar

Dos formas:

- **Con la tarjeta**: pulsa *Escanear* y muestra el QR del PDF a la cámara. El quiosco confirma con nombre, acción y hora, y con una señal sonora.
- **Con código y PIN**: pulsa «Ficha con tu código y PIN», introduce el código de empleado y el PIN. Ficha igual, marcado «por PIN» para que el responsable lo revise.

Repite el fichaje para cerrar el turno: la confirmación muestra el total del día. Si ficha dos veces en pocos segundos, el antirrebote lo absorbe sin error.

### Paso 6 · Verlo en el panel

- *Presencia*: la persona aparece y desaparece en tiempo real, sin recargar.
- *Plantilla → ficha de la persona → jornadas*: la jornada con sus tramos y totales. Desde ahí se puede **corregir** una hora: motivo obligatorio del catálogo, y el tramo original se conserva como versión anterior.
- *Incidencias*: el fichaje por PIN ha dejado una incidencia para revisión.
- *Informes*: horas por persona y por departamento, exportación CSV/XLSX/PDF sellado y la exportación para la Inspección.
- *Cuentas* (con la cuenta de administración): las cuentas del panel, con alta por contraseña temporal y asignación del responsable de cada departamento.

### Paso 7 · El portal del empleado

<https://kronoqr.kodigolab.es/portal/> con el código de empleado y el PIN del paso 2. La persona ve sus jornadas, sus tramos y sus totales, y descarga su histórico (en la 2.2.0, en CSV o en PDF sellado). Solo lectura.

### Paso 8 · Probar el modo sin conexión (opcional)

En la pestaña del quiosco, corta la red del navegador (herramientas de desarrollo → *Network → Offline*) y ficha. El quiosco confirma igual y muestra el contador de la cola. Al restaurar la red, sincroniza y el fichaje aparece en el panel con su hora real (`occurred_at`), no con la de la sincronización.

## 5. Instalar o actualizar a la 2.2.0

La entrega es el paquete `kronoqr-2.2.0.tar.gz` de la [release](https://github.com/BorjaPeonSaiz/KronoQR/releases/tag/v2.2.0), con sus imágenes en GHCR fijadas por digest.

**Instalación limpia**, en cualquier Linux con Docker 24+ y Compose v2: los pasos del [README §3.3](../README.md#33-instalación-en-el-servidor-del-cliente) y, con detalle, la [guía de instalación](../docs/cliente/instalacion.md).

**Actualización desde la 2.1.0**, que es la que se aplicó a esta demostración. Se hace **al lado, no encima**: el paquete nuevo en su propio directorio.

```bash
command -v setpriv                        # hace falta (paquete util-linux)
cd /                                     # en esta demostración la instalación está en la raíz
tar xzf kronoqr-2.2.0.tar.gz              # junto a kronoqr-2.1.0, nunca encima
cd kronoqr-2.2.0
sudo ./update.sh --check-only             # no toca nada: dice qué falta
sudo ./update.sh                          # copia previa, migraciones, verificación; vuelve atrás sola si falla
```

Lo que conviene saber, del [`CHANGELOG.md`](../CHANGELOG.md) y de [`operacion.md`](../docs/cliente/operacion.md) §11:

- **El fichaje no se detiene**: durante la ventana el panel y el portal responden «en mantenimiento» y los quioscos encolan. Las tablets se pasan a la 2.2.0 el mismo día, con la cola vacía.
- **Al terminar, se trabaja solo desde el directorio nuevo.** El anterior queda retirado: su `docker compose` falla a propósito y dice cuál es el vigente.
- **La cuenta de administración tiene que cerrar sesión y volver a entrar** para ver la sección **Cuentas**; los responsables de departamento darán de alta su segundo factor en el primer acceso.
- **Las copias y el WAL pasan a ir cifrados y autenticados**: las copias del WAL de la 2.1.0 que se hubieran sacado del servidor no lo estaban y hay que destruirlas.
- Con el portal abierto a internet, conviene subir el PIN a 8 cifras (`IDENTITY_PIN_LENGTH`); ningún PIN entregado deja de valer.

El procedimiento completo, con la vuelta atrás a mano, está en el runbook [`actualizacion-cliente.md`](../docs/runbooks/actualizacion-cliente.md).

## 6. Qué no se puede probar aquí

- El **instalador y el actualizador** (`install.sh`, `update.sh`): necesitan un servidor propio. La CI los ejercita en cada cambio con una instalación limpia y una actualización desde la versión anterior con vuelta atrás (jobs ⑧ y ⑧b de `ci.yml`), y la [guía de instalación](../docs/cliente/instalacion.md) permite repetirlo en cualquier Linux con Docker.
- La **tablet Android real** en una pared: el quiosco está probado como PWA en navegador, y la prueba en una tablet durante jornadas completas está pendiente. La demostración corre en un navegador de escritorio.
- La **observabilidad** (Grafana, Prometheus, Alertmanager): forma parte de la instalación pero no está expuesta a internet. En el entorno de desarrollo (`make up`) está en los puertos 3000, 9090 y 9093.
