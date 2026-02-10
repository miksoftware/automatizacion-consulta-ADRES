# Automatización Consulta ADRES

Sistema automatizado para consultar información de afiliación en salud (EPS) desde el portal de [ADRES](https://www.adres.gov.co/consulte-su-eps) a partir de un archivo Excel con números de cédula.

## Características

- Carga de archivo Excel (.xlsx, .xls, .csv) con cédulas
- Pre-validación del archivo antes de procesar
- Estimación de tiempo antes de iniciar
- Progreso en tiempo real vía SSE (Server-Sent Events)
- Reintentos automáticos (3 intentos por cédula)
- Descarga de resultados en Excel
- Historial de consultas anteriores

## Requisitos del Servidor

- **Ubuntu 22.04+** (probado en Ubuntu)
- **PHP 8.4** con extensiones: mbstring, xml, zip, gd, sqlite3, curl, dom
- **Composer 2.x**
- **Node.js 20+** y npm
- **Google Chrome** (última versión estable)
- **ChromeDriver** (misma versión que Chrome)
- **Nginx**
- **Git**
- Mínimo **2 GB RAM** (Chrome headless consume memoria)
- Mínimo **2 GB de espacio en disco**

---

## Instalación Paso a Paso

### 1. Actualizar el sistema

```bash
sudo apt update && sudo apt upgrade -y
```

### 2. Instalar PHP 8.4 y extensiones

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli php8.4-mbstring php8.4-xml \
    php8.4-zip php8.4-gd php8.4-sqlite3 php8.4-curl php8.4-dom \
    php8.4-bcmath php8.4-intl
```

### 3. Instalar Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 4. Instalar Node.js 20

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

### 5. Instalar Nginx

```bash
sudo apt install -y nginx
```

### 6. Instalar Google Chrome

```bash
wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | \
    sudo gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg

echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] \
    http://dl.google.com/linux/chrome/deb/ stable main" | \
    sudo tee /etc/apt/sources.list.d/google-chrome.list

sudo apt update
sudo apt install -y google-chrome-stable
```

Verificar la versión instalada:

```bash
google-chrome --version
# Ejemplo: Google Chrome 144.0.7559.132
```

### 7. Instalar ChromeDriver (debe coincidir con la versión de Chrome)

Visitar https://googlechromelabs.github.io/chrome-for-testing/ y descargar la versión que coincida con Chrome:

```bash
# Ejemplo para Chrome 144.0.7559.132
cd /tmp
wget https://storage.googleapis.com/chrome-for-testing-public/144.0.7559.132/linux64/chromedriver-linux64.zip
unzip chromedriver-linux64.zip
sudo mv chromedriver-linux64/chromedriver /usr/local/bin/chromedriver
sudo chmod +x /usr/local/bin/chromedriver
```

Verificar:

```bash
chromedriver --version
# Debe coincidir con la versión de Chrome
```

### 8. Clonar el proyecto

```bash
cd /var/www
git clone https://github.com/miksoftware/automatizacion-consulta-ADRES.git automatizacion
cd automatizacion
```

### 9. Instalar dependencias

```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

### 10. Configurar el entorno

```bash
cp .env.example .env
php artisan key:generate
```

Editar `.env`:

```bash
nano .env
```

Configurar estos valores:

```env
APP_NAME="Consulta ADRES"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://TU_IP:8080

DB_CONNECTION=sqlite
```

### 11. Crear la base de datos SQLite y ejecutar migraciones

```bash
touch database/database.sqlite
php artisan migrate --force
```

### 12. Permisos

```bash
sudo chown -R www-data:www-data /var/www/automatizacion
sudo chmod -R 775 storage bootstrap/cache
sudo chmod -R 775 database
```

Crear directorio para Chrome:

```bash
mkdir -p storage/chrome
sudo chown -R root:root storage/chrome
```

### 13. Crear enlace simbólico de storage

```bash
php artisan storage:link
```

---

## Configuración de Servicios

### 14. Configurar ChromeDriver como servicio

```bash
sudo nano /etc/systemd/system/chromedriver.service
```

Pegar este contenido:

```ini
[Unit]
Description=ChromeDriver
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/chromedriver --port=9515 --whitelisted-ips= --verbose
Restart=always
RestartSec=5
Environment=HOME=/root
Environment=DISPLAY=:99

[Install]
WantedBy=multi-user.target
```

> **IMPORTANTE:** No agregar `User=www-data` ni restricciones de seguridad. ChromeDriver debe ejecutarse como root para que Chrome funcione correctamente en modo headless.

Activar y arrancar:

```bash
sudo systemctl daemon-reload
sudo systemctl enable chromedriver
sudo systemctl start chromedriver
```

Verificar que funciona:

```bash
sudo systemctl status chromedriver
curl http://localhost:9515/status
# Debe responder: {"value":{"ready":true, ...}}
```

### 15. Configurar Nginx

Crear el archivo de configuración:

```bash
sudo nano /etc/nginx/sites-available/automatizacion
```

Pegar este contenido (ajustar el puerto si es necesario):

```nginx
server {
    listen 8080;
    server_name _;
    root /var/www/automatizacion/public;
    index index.php;

    client_max_body_size 50M;

    # Timeouts largos para las consultas SSE
    proxy_read_timeout 600;
    proxy_connect_timeout 600;
    proxy_send_timeout 600;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 600;
        fastcgi_send_timeout 600;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Activar el sitio:

```bash
sudo ln -sf /etc/nginx/sites-available/automatizacion /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

### 16. Configurar PHP-FPM

Editar la configuración del pool:

```bash
sudo nano /etc/php/8.4/fpm/pool.d/www.conf
```

Ajustar estos valores para soportar consultas largas:

```ini
request_terminate_timeout = 600
pm.max_children = 10
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
```

Editar `php.ini`:

```bash
sudo nano /etc/php/8.4/fpm/php.ini
```

Ajustar:

```ini
max_execution_time = 600
memory_limit = 512M
upload_max_filesize = 50M
post_max_size = 50M
```

Reiniciar PHP-FPM:

```bash
sudo systemctl restart php8.4-fpm
```

---

## Verificación

### 17. Verificar que todos los servicios están corriendo

```bash
sudo systemctl status chromedriver   # Debe estar active (running)
sudo systemctl status php8.4-fpm     # Debe estar active (running)
sudo systemctl status nginx          # Debe estar active (running)
```

### 18. Probar Chrome manualmente

```bash
curl http://localhost:9515/status
```

Debe responder con `"ready":true`.

### 19. Acceder a la aplicación

Abrir en el navegador:

```
http://TU_IP:8080
```

---

## Uso

1. **Subir archivo Excel**: Clic en "Seleccionar archivo Excel" y elegir un `.xlsx`, `.xls` o `.csv` con una columna de cédulas
2. **Validar**: El sistema detecta las cédulas y muestra un resumen con tiempo estimado
3. **Procesar**: Clic en "Iniciar Consulta" para comenzar
4. **Progreso en tiempo real**: Se muestra la cédula actual, nombre encontrado, EPS, tiempo transcurrido y restante
5. **Descargar**: Al finalizar, descargar el Excel con todos los resultados

### Formato del archivo de entrada

El Excel debe tener una columna con números de cédula. El sistema detecta automáticamente la columna correcta. Ejemplo:

| Cédula     |
|------------|
| 1101260509 |
| 1003812935 |
| 36065640   |

### Datos que se extraen por cada cédula

| Campo               | Descripción                      |
|---------------------|----------------------------------|
| Tipo de documento   | CC, TI, CE, etc.                 |
| Nombres             | Nombre(s) del afiliado           |
| Apellidos           | Apellido(s) del afiliado         |
| Fecha de nacimiento | Fecha de nacimiento              |
| Departamento        | Departamento de residencia       |
| Municipio           | Municipio de residencia          |
| Estado              | Estado de afiliación             |
| Entidad/EPS         | EPS a la que está afiliado       |
| Régimen             | Contributivo / Subsidiado        |
| Fecha de afiliación | Fecha de inicio de afiliación    |
| Fecha de finalización | Fecha de fin (si aplica)       |
| Tipo de afiliado    | Cotizante / Beneficiario         |

---

## Solución de Problemas

### Los logs no muestran nada

```bash
cat storage/logs/laravel.log
```

Si está vacío, verificar permisos:

```bash
sudo chown -R www-data:www-data storage
sudo chmod -R 775 storage
```

### ChromeDriver no arranca

```bash
sudo systemctl status chromedriver
sudo journalctl -u chromedriver -n 50
```

Verificar que las versiones coinciden:

```bash
google-chrome --version
chromedriver --version
```

### Error "session not created"

Las versiones de Chrome y ChromeDriver no coinciden. Descargar el ChromeDriver correcto desde:
https://googlechromelabs.github.io/chrome-for-testing/

### Timeout en todas las cédulas

1. Verificar que ADRES está accesible desde el servidor:
```bash
curl -I https://www.adres.gov.co/consulte-su-eps
```

2. Limpiar el directorio de Chrome y reiniciar:
```bash
rm -rf storage/chrome/*
sudo systemctl restart chromedriver
sudo systemctl restart php8.4-fpm
```

### Limpiar logs

```bash
echo "" > storage/logs/laravel.log
```

---

## Actualización

Para actualizar la aplicación a la última versión:

```bash
cd /var/www/automatizacion
git pull
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate --force
rm -rf storage/chrome/*
sudo systemctl restart chromedriver
sudo systemctl restart php8.4-fpm
```

---

## Arquitectura Técnica

### Flujo de consulta (2 pasos via fetch)

```
Usuario sube Excel
    → Laravel valida y cuenta cédulas
    → SSE inicia streaming de progreso
    → Por cada cédula:
        1. Chrome navega a adres.gov.co/consulte-su-eps
        2. Encuentra el iframe con el formulario
        3. Ingresa la cédula en el campo txtNumDoc
        4. POST del formulario vía fetch() (JavaScript)
        5. ADRES responde con: window.open('RespuestaConsulta.aspx?tokenId=XXX')
        6. Se extrae la URL con tokenId vía regex
        7. GET a esa URL vía fetch() (segundo request)
        8. Se recibe el HTML con los datos
        9. Se parsea con DOMDocument (PHP) — GridViewBasica y GridViewAfiliacion
    → Genera Excel con resultados
    → Usuario descarga el archivo
```

### ¿Por qué fetch() en vez de click directo?

ADRES usa `window.open()` para abrir los resultados en una nueva pestaña del navegador. En Chrome headless en Linux, las nuevas pestañas fallan de forma intermitente. La solución implementada:

1. **POST** el formulario vía `fetch()` desde JavaScript dentro del iframe
2. La respuesta HTML contiene `window.open('RespuestaConsulta.aspx?tokenId=XXX')`
3. Se extrae la URL del `tokenId` con regex en PHP
4. Se hace **GET** a esa URL vía `fetch()` (segundo request)
5. El HTML de respuesta se parsea directamente en PHP con `DOMDocument`

Esto elimina completamente la dependencia de nuevas pestañas y es más rápido.

### Stack Tecnológico

| Componente | Tecnología |
|------------|------------|
| Backend    | Laravel 12 / PHP 8.4 |
| Base de datos | SQLite |
| Scraper    | Selenium WebDriver (facebook/webdriver) + ChromeDriver |
| Frontend   | Blade + Tailwind CSS 4 |
| Progreso   | Server-Sent Events (SSE) |
| Excel      | Maatwebsite/Excel 3.x |
| Servidor   | Nginx + PHP-FPM |

---

## Licencia

Proyecto privado de [MikSoftware](https://github.com/miksoftware).
