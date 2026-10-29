# GOLDEN TOUCH · instalación en Hostinger (PHP + MySQL)

Esta versión guarda **todo en tu base de datos MySQL** (productos, pedidos, clientes, créditos, usuarios, cierres de caja). El navegador solo recuerda el carrito y las preferencias de cada visitante. Funciona en los planes de hosting compartido de Hostinger (Premium, Business, Cloud): solo necesita **PHP 8.0 o superior** y **MySQL**.

## Qué trae el paquete
```
public_html/
├─ index.html            la tienda (y el panel de administración)
├─ sw.js, manifest.webmanifest, icons/   instalable como app
├─ .htaccess             https obligatorio y enrutamiento de /api
└─ api/
   ├─ index.php, core.php, logic.php, mail.php   el servidor
   ├─ cron.php           carritos abandonados (opcional)
   ├─ check.php          diagnóstico de la instalación
   ├─ config.sample.php  plantilla de configuración
   └─ data/seed.json     productos y usuarios de ejemplo (se cargan la 1.ª vez)
```

## Paso a paso

### 1. Crear la base de datos MySQL
1. hPanel → **Bases de datos → Gestión de bases de datos**.
2. Crea una base nueva: elige nombre, usuario y contraseña. Hostinger les pone un prefijo (por ejemplo `u123456789_tienda`).
3. Anota los **tres datos** (base, usuario, contraseña). El servidor es `localhost`.

### 2. Subir los archivos
1. hPanel → **Administrador de archivos** → carpeta **`public_html`**.
2. Borra lo que haya por defecto (por ejemplo `default.php`).
3. Sube **`golden-touch-hostinger-php.zip`** y pulsa **Extraer** dentro de `public_html`.
4. Comprueba que `index.html`, `.htaccess` y la carpeta `api` quedaron **directamente dentro de `public_html`** (no dentro de otra carpeta). Si no ves `.htaccess`, activa "Mostrar archivos ocultos" en la configuración del administrador de archivos.

### 3. Configurar la conexión
1. En `public_html/api/` renombra **`config.sample.php` → `config.php`** y ábrelo con el editor.
2. Escribe `db_name`, `db_user` y `db_pass` (los del paso 1). Deja `db_host` en `localhost`.
3. En `cron_key` escribe un texto largo y secreto (lo usarás para el diagnóstico y el cron).
4. Guarda.

### 4. Probar
1. Abre `https://tudominio.com/api/check.php?key=TU_CRON_KEY`. Todo debe decir **OK** (la línea "tienda inicializada" puede decir AVISO hasta que abras el sitio).
2. Abre `https://tudominio.com`. La primera vez crea las tablas y carga los datos de ejemplo.
3. Entra a **Ingresar** con `admin` / `admin123` y **cambia la contraseña ya** en *Equipo*. Cambia también (o elimina) la empleada de ejemplo `camila`.
4. En **Ajustes** escribe tu WhatsApp, correo, dirección, cuentas bancarias y el **enlace público de la tienda** (`https://tudominio.com`).
5. Borra los productos de ejemplo y sube los tuyos (pestaña **Productos** o **Excel**).

**Cómo saber que guarda en MySQL:** crea un producto, abre la tienda desde otro dispositivo (o en una ventana de incógnito) y debe aparecer. En hPanel → phpMyAdmin verás las tablas `gt_docs`, `gt_meta`, `gt_secrets`, `gt_sessions`.

Si la base de datos no conecta, la tienda muestra un aviso claro en pantalla (no se pone a guardar en el navegador). Revisa `config.php` y `check.php`.

### 5. Seguridad (importante)
- Activa el **SSL** del dominio en hPanel (el `.htaccess` redirige todo a https).
- `api/config.php` y `api/data` quedan bloqueados desde internet por el `.htaccess` de `api/`.
- El servidor recalcula cada pedido con los precios reales de la base de datos; un cliente no puede cambiar precios ni su cupo.
- Hay límite de intentos de inicio de sesión (10 por 10 minutos por IP).

## Opcionales
**Carritos abandonados por correo automático.** hPanel → Avanzado → **Cron Jobs** → cada 10 minutos:
```
php /home/TU_USUARIO/domains/TUDOMINIO.COM/public_html/api/cron.php
```
(La ruta exacta aparece en el administrador de archivos.) Necesitas activar "Carritos" y "Correos" en el panel.

**Correos (confirmación de pedido, avisos de envío).** Cuenta en resend.com con tu dominio verificado → en `config.php` llena `resend_key` y `mail_from`. Luego activa los correos en **Ajustes** del panel.

**WhatsApp automático.** WhatsApp Cloud API de Meta → `wa_token` y `wa_phone_id` en `config.php`. Sin esto sigue funcionando el botón manual "Avisar".

## Respaldos
- **Ajustes → Descargar respaldo de la base de datos** (solo administrador): baja un archivo JSON con todo.
- hPanel → phpMyAdmin → **Exportar** la base completa, o los respaldos automáticos de Hostinger.

## Actualizar a una versión nueva
Sube los archivos nuevos **sin tocar `api/config.php`** (ni borrar la base de datos). Los datos no se pierden porque viven en MySQL. Si cambió `index.html`, pide a los clientes recargar (o abre en incógnito) por el caché.

## Si algo falla
| Síntoma | Qué hacer |
|---|---|
| Pantalla "no puede conectarse a la base de datos" | Revisa `api/config.php`; abre `api/check.php?key=...` |
| `check.php` dice FALLA en una extensión | hPanel → Avanzado → Configuración de PHP → extensiones: activa `pdo_mysql`, `curl`, `mbstring` |
| Error 404 en `/api/...` | Falta el `.htaccess` de `public_html` (archivos ocultos) o `mod_rewrite` |
| Error "Origen no permitido" | Abres el sitio con un dominio y la API recibe otro (por ejemplo `www` vs sin `www`); usa siempre el mismo |
| Pedidos grandes con fotos fallan al guardar | Sube `post_max_size` y `upload_max_filesize` a 32M en Configuración de PHP |
| No llegan correos | Revisa `resend_key`/`mail_from` y que "Correos" esté activo en Ajustes |

## Límites
- Los avisos en vivo (campana y sonido) llegan por consulta cada ~7 segundos, no al instante.
- Instálala en la raíz de un dominio o subdominio (no en una subcarpeta).
- Una sola tienda por base de datos.
