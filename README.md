# GOLDEN TOUCH en Cloudflare (Workers + D1)

Tienda completa: la web se sirve desde Cloudflare, los datos viven en **D1** (base de datos SQL) y los avisos en vivo (solicitudes de crédito, aprobaciones, sonido) van por **WebSocket** con un Durable Object.

## Requisitos
- Cuenta gratuita de Cloudflare (https://dash.cloudflare.com/sign-up)
- Node 20+ en tu computador

## Publicar (3 comandos)
```bash
cd cf
npm install
npx wrangler login
node setup.mjs     # crea la base de datos D1 "goldentouch", guarda su id y publica la tienda
```
Te dará una URL `https://goldentouch.<tu-subdominio>.workers.dev`. Las tablas se crean solas y se cargan los datos de ejemplo en la primera visita.

**Acceso inicial:** usuario `admin`, contraseña `admin123` (cámbiala en Equipo).

## Dominio goldentouch.pro (comprado en Hostinger)
1. En Cloudflare: *Add a domain* → `goldentouch.pro` (plan Free). Te da 2 nameservers.
2. En Hostinger → Dominios → goldentouch.pro → DNS / Nameservers → *Cambiar nameservers* → pega los de Cloudflare. (Tarda de minutos a unas horas.)
3. En Cloudflare → Workers & Pages → goldentouch → Settings → Domains & Routes → *Add* → Custom domain → `goldentouch.pro` (y `www.goldentouch.pro`).
HTTPS es automático.

## Copias de seguridad
- Como admin entra a `/api/backup` (descarga un JSON con todo).
- O desde consola: `npx wrangler d1 export goldentouch --remote --output=backup.sql`

## Desarrollo local
`npx wrangler dev` (usa un D1 local simulado).

## Límites del plan gratuito (a tener en cuenta)
- Cada Worker request gratis tiene ~10 ms de CPU: la tienda está optimizada (imágenes sin analizar), pero un catálogo muy grande o fotos muy pesadas pueden requerir el plan Workers Paid (5 USD/mes).
- D1 limita cada fila a 2 MB: la app rechaza documentos > 1,8 MB (comprime las fotos de productos).
- D1 gratis: 5 GB, 5 M lecturas/día, 100 k escrituras/día.

## Estructura
- `src/worker.mjs` API + Durable Object `Hub`
- `src/logic.mjs` cálculos de precios/créditos (generado por `build_cf.py`)
- `src/seed.json` datos iniciales
- `public/` la tienda (PWA instalable)

## Mostrador y cierre de caja
Crea un empleado con el rol **Cajero** en Equipo y roles. Abre caja (fondo inicial), vende en **Mostrador** y cierra la caja contando el efectivo. Admin y gerente ven los cierres y sus diferencias en **Cierres de caja**.

## Sin internet
La tienda sigue funcionando si se cae el internet: muestra los últimos datos descargados, permite vender (mostrador, pedidos) y abrir/cerrar caja, y guarda todo en el dispositivo. Aparece un aviso “Sin conexión” con los cambios por enviar; al volver la conexión se envían solos y se descargan los datos nuevos. Necesita haber abierto la tienda con internet al menos una vez, y iniciar sesión sí requiere conexión. Si dos personas venden la última unidad a la vez, el servidor acepta la primera y avisa en la segunda.

## Códigos de producto, SKU y lector
Cada producto tiene un campo **Código (barras / SKU)** (puedes escribirlo, escanearlo o pulsar *Generar*; en Productos hay *Generar códigos faltantes*). En **Mostrador**, la pistola lectora (USB o Bluetooth, que escribe el código y pulsa Enter) agrega el producto a la venta al leerlo, aunque no tengas el cursor en el buscador; repetir la lectura suma 1 unidad. También puedes escribir el código y pulsar Enter, o usar el botón *Escanear* con la cámara (Chrome en Android/PC). Suena un pitido al leer y otro grave si no existe.

## Excel
Pestaña **Excel** (administrador y gerente): descarga la plantilla, llénala con tus productos (SKU, código, nombre, categoría, precio, stock, imágenes por URL…) y súbela; verás una vista previa con errores antes de guardar. Si el SKU ya existe, el producto se actualiza (también sirve para cambiar precios y stock masivamente con el inventario descargado). **Descargar inventario** genera un .xlsx con el mismo formato. **Descargar ventas** (solo administrador) genera el Excel de un día, mes o año con resumen, ventas, detalle por producto y cierres de caja.

## Animaciones
En **Ajustes → Animaciones decorativas** el administrador elige: mariposas brillantes (por defecto), colibríes rosados o apagadas. Aplica a la tienda, el mostrador y el recuadro de ingreso.

## Contenido de la tienda (admin)
En el panel admin, pestaña **Contenido de la tienda**: garantía, historia de la marca (con foto), guía de tallas, reseñas de clientas y enlace de pago en línea (Wompi, Mercado Pago, etc.). En **Categorías** puedes subir una foto por categoría. Lo que se deja vacío no aparece en la tienda. El pago en línea usa un enlace de pago que creas en tu cuenta del proveedor; el pago se confirma manualmente.

## Reseñas de clientas
Las clientas registradas dejan su reseña (1 a 5 estrellas y un comentario) desde **Mi cuenta**. Se publica al instante, solo una vez por clienta, y no se puede editar ni borrar. Solo el administrador puede eliminarlas (Admin → Contenido de la tienda → Reseñas de clientas). Las reglas se aplican también en el servidor.

## Seguimiento del pedido y correos al cliente

**Seguimiento.** En Pedidos, cada pedido a domicilio tiene un botón "Seguimiento" (estado, transportadora, número de guía y mensaje). El cliente lo ve en su factura, en "Mi cuenta" y en "Rastrear pedido" (número de pedido + teléfono).

**Correos automáticos (opcional).** Usan [Resend](https://resend.com) (plan gratis: 3.000 al mes). Verifica tu dominio en Resend, crea una API key y guárdala en el Worker:

```
npx wrangler secret put RESEND_API_KEY
npx wrangler secret put MAIL_FROM        # ej: Golden Touch <pedidos@tudominio.com>
```

Luego, en Admin → Ajustes, marca "Enviar correos automáticos". Se envía al crear el pedido, al confirmar el pago y al cambiar el seguimiento (solo si el cliente dejó su correo). Los correos salen en español.

## Puntos, referidos y carritos abandonados
Todo se enciende y apaga desde el panel de administrador: **Puntos y referidos** y **Carritos abandonados**.

- **Puntos y referidos:** se calculan en el Worker con la misma lógica de la tienda; la clienta no puede modificar sus puntos. Cada clienta tiene un código `GT-XXXXXX` y un enlace para compartir.
- **Carritos abandonados:** se guardan cuando alguien escribe su teléfono o correo en el pago. Desde el panel se le escribe por WhatsApp o se le envía un correo con un clic.
- **Correo automático (opcional):** `wrangler.jsonc` incluye una tarea programada (`triggers.crons`, cada 10 minutos) que envía un único correo por carrito abandonado. Necesita `RESEND_API_KEY` y `MAIL_FROM` (ver sección de correos), correos activados en Ajustes y la **dirección web de la tienda** en Ajustes. Máximo 15 correos por ejecución y uno por dirección cada 7 días. Si no quieres envío automático, deja esa opción apagada: el resto funciona igual.

## Recoger en tienda, avisos por WhatsApp y reportes
- **Recoger en tienda:** Admin → Envíos → "Recoger en tienda". La clienta lo elige en el pago (sin costo de envío) y la dirección y el horario salen en la factura, el correo y el seguimiento.
- **Avisos por WhatsApp:** Admin → Avisos por WhatsApp. El botón **Avisar** en Pedidos siempre funciona. Para envío **automático** necesitas la API oficial de WhatsApp Business (Meta): crea las plantillas sugeridas y guarda los secretos: `npx wrangler secret put WA_TOKEN` y `npx wrangler secret put WA_PHONE_ID`.
- **Reportes:** Admin/Gerente → Reportes: ventas, costo, ganancia y margen. El costo de cada producto (Productos → Editar) solo lo ven administrador y gerente.

## Hostinger (PHP + MySQL)

La carpeta `hostinger/public_html/` contiene todo lo necesario: sube **su contenido** a `public_html` en Hostinger
(incluido el archivo oculto `.htaccess`), crea la base MySQL desde hPanel y copia `api/config.sample.php` a `api/config.php`
con los datos de tu base (nunca se sube a GitHub). Guía completa: `hostinger/public_html/INSTALAR_HOSTINGER.md`.
Diagnóstico: `https://TU-DOMINIO/api/check.php`.
