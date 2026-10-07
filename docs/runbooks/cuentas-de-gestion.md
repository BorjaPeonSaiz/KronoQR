# Runbook — alta y baja de cuentas de gestión

**No responde a una alerta: responde a una persona que entra o sale.** Alguien
empieza a necesitar el panel de gestión (dirección, RRHH, un responsable de
departamento, auditoría) o deja de necesitarlo. Desde la 2.2.0 todo el ciclo de
vida de esas cuentas se lleva **desde el panel**, en la sección **Cuentas**, y
la consola del servidor queda como **vía de recuperación**.

**Impacto en el fichaje: ninguno.** Las cuentas de gestión no fichan: la
plantilla ficha con su tarjeta y su PIN, que no tienen nada que ver con esto.
Ninguna acción de este runbook deja a nadie sin fichar ni toca un registro de
jornada.

**Destinatario: quien tiene el rol de administración** (las secciones 1 a 4) y
**el IT del hotel** (la verificación de la sección 5 y la recuperación por
consola de la sección 6). La versión para RRHH, sin comandos, está en
[`../cliente/guia-rrhh.md`](../cliente/guia-rrhh.md) §7 bis.

**Lo que no se hace nunca: tocar cuentas o responsables por SQL.** Ni para
crear, ni para dar de baja, ni para cambiar una contraseña, ni para asignar el
responsable de un departamento. El panel y la consola dejan constancia de quién
lo hizo y por qué; una edición directa de la base no, y además se salta las
guardas que impiden quedarse sin administrador.

---

## 0. Antes de nada: tras actualizar a la 2.2.0, vuelve a entrar

Los permisos de una sesión del panel se fijan al abrirla. **Las sesiones
abiertas antes de actualizar a la 2.2.0 no llevan el permiso de gestionar
cuentas**, así que quien tenga el rol de administración:

- no ve **Cuentas** en el menú, y
- en **Departamentos** no puede elegir responsable.

**Qué hacer:** cerrar sesión («Cerrar sesión», en el menú del panel) y volver
a entrar. No es un fallo ni hace falta tocar nada en el servidor. Si después de
volver a entrar sigue sin aparecer, la cuenta no tiene rol de administración:
compruébalo con `identity:list-users` (sección 6).

---

## 1. Alta de una cuenta de gestión

**Quién:** una persona con rol de administración, en Panel → **Cuentas** →
**«Nueva cuenta»**.

1. **Nombre** de la persona —no el del puesto: es el que aparece como autor de
   cada corrección que firme—, **correo** y **rol**. El correo es solo su
   identificador de acceso; el producto no le envía nada.

   | Rol | Para quién |
   | --- | --- |
   | Administración | Dirección e IT del hotel. Configuración, licencia, cuentas |
   | RRHH | Quien lleva la plantilla, las correcciones y la Inspección |
   | Responsable de departamento | Jefes de sala, de pisos, de cocina. **Solo su departamento**, y solo cuando se le asigne (sección 3) |
   | Auditoría | Consulta, sin corregir nada |

2. **Confirma con tu segundo factor.** El diálogo pide **tu** código de la
   aplicación de autenticación (o tu contraseña actual, si tu cuenta todavía no
   tiene segundo factor). Es a propósito: una sesión abierta en un ordenador
   ajeno no basta para crear cuentas. Tras varios códigos fallidos espera los
   segundos que indica.
3. **«Crear cuenta».** Aparece la **contraseña temporal**, **una sola vez**.
   Anótala y entrégala **en mano** o de viva voz, nunca por correo ni por
   mensajería. Marca «La he entregado en mano a…» y cierra. Si la pierdes antes
   de entregarla, restablécela (sección 4.1): no se puede volver a consultar.
4. **La persona entra el mismo día.** Con su correo y la contraseña temporal,
   el panel le pide primero **dar de alta su segundo factor** (código QR en su
   teléfono) y después **fijar su propia contraseña**. Hasta entonces no puede
   hacer nada más que eso o salir.

**La temporal caduca** a las `IDENTITY_TEMPORARY_PASSWORD_TTL_HOURS` (72 de
serie, de 1 a 168; [`../cliente/configuracion.md`](../cliente/configuracion.md)
§6.8). Caducada, el acceso responde lo mismo que con una contraseña incorrecta;
en la lista de **Cuentas** la fila dice «Temporal caducada». Restablécela.

**Si el correo ya existe** —activo o dado de baja— el panel lo rechaza: usa
otro. Una cuenta dada de baja no se reactiva (sección 2).

**Alta con rol de administración:** salta la alerta
`KronoqrManagementAdminAccountCreated` al responsable de seguridad, **siempre**.
Avísale antes. Si la recibe sin saberlo, sigue
[`ataque-a-credenciales.md`](ataque-a-credenciales.md) §9.

---

## 2. Baja de una cuenta de gestión

**Cuándo:** el mismo día en que esa persona deja el hotel o pasa a un puesto
sin acceso al panel. Una cuenta de alguien que ya no está es acceso a los datos
de la plantilla y a las correcciones de jornada.

1. Panel → **Cuentas** → **«Dar de baja»** en su fila.
2. **Motivo**, obligatorio: queda en el registro de auditoría. Sin datos de
   salud ni juicios de valor («Deja el hotel», «Cambio de puesto»).
3. **«Dar de baja».**

**Qué pasa:**

- Deja de poder entrar **en el acto**, también en las sesiones que tuviera
  abiertas: su siguiente petición recibe `401`.
- **Los accesos de soporte vigentes que esa cuenta concedió se retiran**, cada
  uno con su asiento `support_grant.revoked`.
- **No se borra nada.** La cuenta sigue siendo la autora de todo lo que firmó.
- **No se reactiva.** Si vuelve, se le crea una cuenta nueva con otro correo.

**Lo que el panel no admite, y responde con un aviso sin cambiar nada:**

- darte de baja a ti;
- dar de baja la **última** cuenta de administración activa. Crea otra antes.

**Si era responsable de un departamento**, elige al nuevo en Departamentos
(sección 3). Mientras tanto, ese departamento se comporta como si no tuviera
responsable: RRHH y administración lo siguen viendo entero.

**Una conexión en tiempo real ya abierta** (la pantalla de Presencia que
estuviera mirando) puede seguir recibiendo avisos hasta que se recargue o se
reconecte; cualquier petición nueva ya se rechaza. Si la baja es por un
incidente de seguridad, sigue además
[`ataque-a-credenciales.md`](ataque-a-credenciales.md) §9.3.

---

## 3. Responsable de departamento

El alcance de un responsable de departamento **lo da el departamento**, no su
cuenta. Una cuenta nueva con ese rol **no ve a nadie** hasta que se le asigna un
departamento; la lista de **Cuentas** lo avisa en su fila («No alcanza a
nadie») con un enlace para asignarlo.

1. Panel → **Departamentos** (con rol de administración).
2. En la fila del departamento, elige la cuenta responsable. Solo aparecen
   cuentas **activas** con el rol de responsable de departamento.
3. **«Guardar».**

Un departamento tiene un solo responsable. **Elegir a otro desplaza al
anterior**, que pierde el alcance en su siguiente petición. Quedan dos asientos
`role_assignment.changed`: uno por la cuenta que deja de serlo y otro por la que
pasa a serlo, con el departamento y si se concede o se retira.

---

## 4. Contraseña olvidada, móvil perdido, cambio propio

### 4.1 Restablecer la contraseña de otra persona

Panel → **Cuentas** → **«Restablecer contraseña»** en su fila, con motivo y tu
código del segundo factor. Sale una contraseña temporal nueva, una sola vez,
que se entrega en mano. Sus sesiones abiertas se cierran. **Su segundo factor no
se toca.** Asiento: `user.password_reset` con actor y motivo.

### 4.2 Restablecer el segundo factor de otra persona

Para quien perdió o cambió el móvil. Panel → **Cuentas** → **«Restablecer
2FA»**, con motivo y tu código. Sus sesiones se cierran y, en su siguiente
acceso con su contraseña de siempre, da de alta el segundo factor de nuevo.

**Hazlo con la persona delante o avisada, y que entre enseguida.** Hasta que lo
dé de alta, quien conozca su contraseña podría darlo de alta por ella; el alta
deja un asiento `auth.two_factor_enabled` con hora e IP, y conviene mirarlo
(sección 5). Salta **siempre** la alerta `KronoqrManagementTwoFactorReset` al
responsable de seguridad. Asiento: `auth.two_factor_reset`.

**Nunca las dos cosas a la vez sin motivo claro.** Restablecer contraseña **y**
segundo factor de la misma cuenta es entregar la cuenta entera: es exactamente
el patrón que vigila [`ataque-a-credenciales.md`](ataque-a-credenciales.md) §9.

### 4.3 Cambiar la propia contraseña

Cualquier cuenta, desde **«Cambiar mi contraseña»** bajo su nombre en el menú.
Pide la actual. Al cambiarla se cierran las demás sesiones de esa cuenta.
Asiento: `user.password_changed`. Tras varios intentos con la contraseña actual
equivocada, la sesión se cierra y deja `auth.lockout_started`.

**Sobre tu propia cuenta** el panel no deja restablecer ni la contraseña ni el
segundo factor: para eso está «Cambiar mi contraseña», y para el segundo factor
perdido, otra persona con rol de administración. **Ten siempre dos cuentas de
administración.**

---

## 5. Verificar en el registro de auditoría lo que se hizo

Todo lo anterior deja asiento en `audit_log`, en la misma transacción que el
cambio: si el asiento no se pudo escribir, el cambio tampoco se hizo. Para ver
los de la última semana, desde el directorio de la instalación:

```bash
docker compose exec -T postgres psql -U fichaje_app -d fichaje -c \
  "SELECT a.occurred_at, a.action, act.uuid AS actor, a.payload->>'user_uuid' AS cuenta, a.payload->>'role' AS rol, a.payload->>'reason' AS motivo, a.payload->>'via' AS via, a.payload->>'department_id' AS departamento, a.payload->>'change' AS cambio, a.ip FROM audit_log a LEFT JOIN users act ON a.actor_type = 'user' AND act.id = a.actor_id WHERE a.action IN ('user.created', 'user.deactivated', 'user.password_reset', 'auth.two_factor_reset', 'auth.two_factor_enabled', 'user.password_changed', 'role_assignment.changed', 'support_grant.revoked') AND a.occurred_at > now() - interval '7 days' ORDER BY a.occurred_at DESC;"
```

Es **solo lectura**. Cómo se lee:

| `action` | Lo provoca | Qué comprobar |
| --- | --- | --- |
| `user.created` | Alta (sección 1) | `actor` es quien la creó; `via` = `panel` o `console`. Va seguido de un `role_assignment.changed` con el `rol` de la cuenta nueva |
| `role_assignment.changed` | Alta, o responsable de departamento (sección 3) | Con `departamento` y `cambio` (`granted`/`revoked`) cuando es un responsable; dos filas por cada cambio de responsable |
| `user.deactivated` | Baja (sección 2) | `actor` y `motivo`. Si concedió accesos de soporte vigentes, cada uno tiene su `support_grant.revoked` justo después |
| `user.password_reset` | Restablecer contraseña (4.1) | `actor` y `motivo` |
| `auth.two_factor_reset` | Restablecer 2FA (4.2) | `actor` y `motivo`. Cerca de un `user.password_reset` de la misma `cuenta`, verifica con las dos personas |
| `auth.two_factor_enabled` | La persona da de alta su segundo factor | **Hora e `ip`**: que sean las esperadas. Si el titular no la reconoce, repite 4.2 y avisa a seguridad |
| `user.password_changed` | Cambio propio (4.3) | `actor` y `cuenta` son la misma |

- **`actor` vacío** significa que se hizo **por consola** (sección 6): una
  orden de consola no tiene sesión detrás y el asiento no se lo atribuye a
  nadie.
- El asiento lleva **UUID**, nunca nombre ni correo. Para saber de quién es un
  UUID, `identity:list-users` (sección 6).

---

## 6. Vía de recuperación: la consola

Para cuando el panel no está disponible o **nadie con rol de administración
puede entrar** (la única perdió el móvil, o se fue sin que hubiera otra). Las
órdenes aplican **las mismas reglas** que el panel —tampoco dan de baja la
última administración— y dejan **los mismos asientos**, sin actor y con
`via: console` en el alta. No piden segundo factor: quien tiene la consola del
servidor ya está dentro.

```bash
# Lista de cuentas: UUID, correo, rol, estado, 2FA y tipo de contraseña. Lleva correos: no la guardes en un fichero
docker compose exec app php artisan identity:list-users

# Solo las activas, o solo un rol
docker compose exec app php artisan identity:list-users --status=active --role=admin

# Alta con su rol. Pregunta nombre y correo, y muestra UNA vez la contraseña temporal
docker compose exec app php artisan identity:create-user --role=admin

# Baja, con motivo
docker compose exec app php artisan identity:deactivate-user persona@tuhotel.example --reason="Deja el hotel"

# Contraseña temporal nueva, mostrada UNA vez
docker compose exec app php artisan identity:reset-password persona@tuhotel.example --reason="Contraseña olvidada"

# Retirar el segundo factor (UUID de la lista)
docker compose exec app php artisan identity:2fa-reset 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90 --reason="Móvil perdido"
```

**Recuperar el acceso de la única cuenta de administración** que perdió el
móvil: `identity:2fa-reset` con su UUID; entra con su contraseña y da de alta el
segundo factor otra vez. Si además olvidó la contraseña, `identity:reset-password`
y entrega la temporal en mano. **Después, crea desde el panel una segunda cuenta
de administración**, para no volver a depender de la consola.

Si una orden dice que la cuenta no existe, revisa el correo o el UUID con
`identity:list-users`: las órdenes no distinguen «no existe» de «dada de baja».

---

## 7. A quién se escala

- **Una alta, baja o restablecimiento que nadie reconoce**, o una alerta
  `KronoqrManagementAdminAccountCreated` / `KronoqrManagementTwoFactorReset`
  sin explicación: es un incidente de seguridad. Sigue
  [`ataque-a-credenciales.md`](ataque-a-credenciales.md) §9 y avisa al
  responsable de protección de datos del hotel.
- **El panel rechaza una acción con un error que no es ninguno de los avisos de
  este runbook**, o la consola falla: genera el paquete de diagnóstico
  ([`../cliente/operacion.md`](../cliente/operacion.md) §12.2) y abre un caso
  con el fabricante. El paquete no lleva correos ni nombres.
