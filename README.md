# ConectaObra

**Red B2B para el sector de la construcción.** Pone en contacto a constructoras, subcontratas, instaladores, ingenierías y proveedores para que encuentren colaboradores y oportunidades sin depender solo de su agenda de contactos.

🌐 En producción: **[conectaobra10.es](https://conectaobra10.es)**

> Proyecto real, publicado y en funcionamiento. Está hecho sin frameworks a propósito: PHP, JavaScript y CSS a pelo, con una base de datos MariaDB.

---

## Qué hace

- **Perfiles de empresa** con especialidad, provincia y zona de actuación, y un **directorio** con búsqueda y filtros.
- **Oportunidades**: las empresas publican necesidades de colaboración y otras empresas las consultan y contactan.
- **Planes de suscripción** (Gratis, Profesional y Premium) cobrados con **Stripe**, con límites distintos de oportunidades y contactos según el plan.
- **Empresas Fundadoras**: las primeras empresas disfrutan de 12 meses del plan Profesional gratis, con recordatorios automáticos por correo (a 30, 7 y 1 día de que caduque).
- **Conexiones y avisos**: cada empresa indica qué ofrece y qué necesita, y la plataforma la avisa cuando aparece algo compatible.
- **Licitaciones** privadas y públicas, con un flujo de aprobación y autorización de contactos por parte de un administrador.
- **Intermediación**: solicitudes de mediación con subida de documentación.
- **Red comercial**: agentes comerciales con acceso propio, y cálculo de comisiones a partir de las facturas pagadas en Stripe.
- **ConectaJob**: una bolsa de empleo del sector (ofertas, candidaturas, perfiles de candidato) que reutiliza la misma base de datos.
- **Panel de administración** para gestionar empresas, invitaciones, oportunidades y moderación.

## Con qué está hecho

| Capa | Tecnología |
|---|---|
| Backend | **PHP 8** (sin framework), `declare(strict_types=1)`, PDO con consultas preparadas |
| Base de datos | **MariaDB / MySQL** (InnoDB, utf8mb4). Esquema en [`public/schema.sql`](public/schema.sql) |
| Frontend | **HTML5, CSS3 y JavaScript** vanilla (ES6+), sin build ni dependencias |
| Pagos | **Stripe**: Checkout, Billing Portal y Webhooks |
| Correo | Cliente **SMTP** propio sobre SSL |
| Servidor | Apache (`.htaccess`) en hosting compartido IONOS |
| Tareas programadas | Cron para los recordatorios de Empresas Fundadoras |

Aproximadamente: ~9.000 líneas de PHP, ~2.700 de HTML, ~2.000 de JavaScript y ~2.600 de CSS.

## Cómo está organizado

```
public/
├── index.html, planes.html, directorio.html…   Páginas públicas (HTML estático)
├── api.php                                      API JSON: una sola entrada, enrutada con ?action=
├── assets/                                      CSS y JS del frontend (app.js, ui.js, ui.css…)
├── admin-nuevo/                                 Panel de administración
├── comercial/                                   Portal de agentes comerciales
├── conectajob/                                  Bolsa de empleo
├── licitaciones-privadas/ · licitaciones-publicas/
├── mega-bloque/                                 Módulos con clases propias:
│   ├── alertas/                                   reservas y cancelaciones de avisos
│   ├── conexiones/                                emparejamiento y avisos
│   └── comerciales/                               agentes, auditoría y administración
├── schema.sql                                   Esquema de la base de datos
├── founder-cron.php                             Recordatorios diarios
└── sales-stripe-commissions.php                 Cálculo de comisiones
```

Hay una explicación más detallada en [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md).

## Seguridad: lo que se ha cuidado

- Contraseñas con `password_hash` / `password_verify`.
- Todas las consultas SQL usan sentencias preparadas.
- Sesiones con cookie `HttpOnly`, `SameSite=Lax` y `Secure` bajo HTTPS; se regenera el ID al iniciar sesión.
- Protección **CSRF** en las operaciones que modifican datos.
- **Límite de intentos** (rate limiting) en login, registro, recuperación de contraseña, formulario de contacto y acceso de administración.
- El webhook de Stripe **verifica la firma HMAC** y rechaza eventos con más de 5 minutos de antigüedad.
- Subida de logos con nombres aleatorios y una carpeta `uploads/` donde no se puede ejecutar código.
- Cabeceras de seguridad (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, HSTS).

## Ponerlo en marcha en local

Necesitas PHP 8.1+ y MariaDB/MySQL.

```bash
git clone https://github.com/maarcoslunna/conectaobra.git
cd conectaobra/public

# 1. Configuración
cp config.example.php config.php            # y rellena tus datos
cp stripe-prices.example.php stripe-prices.php

# 2. Base de datos (o deja que api.php cree las tablas en el primer acceso)
mysql -u root -p -e "CREATE DATABASE conectaobra CHARACTER SET utf8mb4;"
mysql -u root -p conectaobra < schema.sql

# 3. Servidor
php -S localhost:8000
```

Abre `http://localhost:8000`. No abras los `.html` con doble clic: necesitan PHP para hablar con la API.

Para probar pagos usa **claves de test de Stripe** y reenvía los webhooks con la [Stripe CLI](https://stripe.com/docs/stripe-cli):

```bash
stripe listen --forward-to "localhost:8000/api.php?action=stripe_webhook"
```

## Cosas pendientes y mejoras que haría

Es un proyecto que ha crecido rápido, y se nota. Lo que mejoraría con tiempo:

- Partir `api.php` (~1.400 líneas) en controladores y servicios, como ya se hizo en `mega-bloque/`.
- Añadir tests automáticos, empezando por la lógica de planes y de comisiones.
- Usar Composer y un autoloader en lugar de `require` manuales.
- Migraciones de base de datos versionadas, en vez de crear tablas desde el propio código.
- Revisar el módulo de licitaciones (aún conserva nombres de archivo `-prueba`).

## Sobre este repositorio

Es el código de la web en producción, publicado sin credenciales ni datos de usuarios: `config.php`, las claves de Stripe, los logos subidos por empresas y los backups no están incluidos. Por eso hay un `config.example.php` que debes copiar y rellenar para arrancarlo.

## Autor

**Marco Luna** · [GitHub](https://github.com/maarcoslunna) · [LinkedIn](https://www.linkedin.com/in/marco-luna-a40a56424/) · info@conectaobra10.es

## Licencia

Código publicado para consulta y como muestra de trabajo. Todos los derechos reservados; consulta [`LICENSE`](LICENSE). Si quieres reutilizar algo, escríbeme.
