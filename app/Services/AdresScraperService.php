<?php

namespace App\Services;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverWait;
use Illuminate\Support\Facades\Log;

class AdresScraperService
{
    protected ?RemoteWebDriver $driver = null;
    protected string $baseUrl = 'https://www.adres.gov.co/consulte-su-eps';

    /**
     * Número máximo de reintentos por cédula cuando falla la consulta.
     */
    protected int $maxReintentos = 3;

    /**
     * Segundos de espera base entre reintentos (se multiplica por el intento).
     */
    protected int $esperaBaseReintento = 2;

    public function __construct()
    {
        $this->initDriver();
    }

    protected function initDriver(): void
    {
        $options = new ChromeOptions();
        
        // Detectar entorno: producción (Linux) vs desarrollo (Windows)
        $esProduccion = PHP_OS_FAMILY === 'Linux';
        
        $chromeArgs = [
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--window-size=1920,1080',
            '--disable-blink-features=AutomationControlled',
            '--disable-extensions',
            '--disable-popup-blocking',
            '--disable-infobars',
            '--disable-notifications',
            '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
        ];

        // Flags adicionales para servidores Linux (VPS)
        if ($esProduccion) {
            $chromeArgs = array_merge($chromeArgs, [
                '--disable-setuid-sandbox',
                '--disable-features=VizDisplayCompositor',
                '--disable-crash-reporter',
                '--disable-breakpad',
                '--disable-background-networking',
                '--disable-sync',
                '--disable-translate',
                '--disable-default-apps',
                '--no-first-run',
                '--mute-audio',
                '--hide-scrollbars',
                '--ignore-certificate-errors',
            ]);
            
            $userDataDir = '/var/www/automatizacion/storage/chrome';
            if (!is_dir($userDataDir)) {
                @mkdir($userDataDir, 0775, true);
            }
            $chromeArgs[] = '--user-data-dir=' . $userDataDir;
            $chromeArgs[] = '--crash-dumps-dir=/tmp';
        }

        $options->addArguments($chromeArgs);

        $options->setExperimentalOption('excludeSwitches', ['enable-automation']);
        $options->setExperimentalOption('useAutomationExtension', false);

        // Permitir popups/nuevas ventanas (crítico para que ADRES abra pestaña de resultados)
        $prefs = [
            'profile.default_content_setting_values.popups' => 0,
            'profile.default_content_settings.popups' => 0,
        ];
        $options->setExperimentalOption('prefs', $prefs);

        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
        $capabilities->setCapability('pageLoadStrategy', 'normal');

        $this->driver = RemoteWebDriver::create(
            'http://localhost:9515',
            $capabilities,
            120000, // 2 minutos de timeout de conexión
            120000  // 2 minutos de timeout de request
        );

        $this->driver->executeScript("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})");
    }

    /**
     * Consulta una cédula con reintentos automáticos.
     * Si la consulta falla (error de conexión, página no cargó, datos no encontrados
     * por timeout), reintenta hasta $maxReintentos veces.
     */
    public function consultarCedula(string $cedula): array
    {
        $ultimoResultado = $this->resultadoVacio($cedula);

        for ($intento = 1; $intento <= $this->maxReintentos; $intento++) {
            Log::info("Consulta cédula {$cedula} — intento {$intento}/{$this->maxReintentos}");

            $resultado = $this->intentarConsulta($cedula);

            // Si fue exitosa (sin error), retornar inmediatamente
            if (empty($resultado['error'])) {
                Log::info("Cédula {$cedula} exitosa en intento {$intento}: {$resultado['nombres']} {$resultado['apellidos']}");
                return $resultado;
            }

            $ultimoResultado = $resultado;

            // Si el error indica que la cédula realmente no existe en ADRES, no reintentar
            if ($this->esErrorDefinitivo($resultado['error'])) {
                Log::info("Cédula {$cedula} — error definitivo: {$resultado['error']}");
                return $resultado;
            }

            // Error transitorio: reintentar con espera progresiva
            if ($intento < $this->maxReintentos) {
                $espera = $this->esperaBaseReintento * $intento;
                Log::warning("Cédula {$cedula} — intento {$intento} falló ({$resultado['error']}). Reintentando en {$espera}s...");

                // Reiniciar el driver para tener un estado limpio
                $this->reiniciar();
                sleep($espera);
            }
        }

        Log::error("Cédula {$cedula} — agotados {$this->maxReintentos} reintentos. Último error: {$ultimoResultado['error']}");
        return $ultimoResultado;
    }

    /**
     * Determina si un error es definitivo (la cédula realmente no existe)
     * o transitorio (problema de carga/timeout que se puede reintentar).
     */
    protected function esErrorDefinitivo(string $error): bool
    {
        $erroresDefinitivos = [
            'no se encontraron datos',
            'documento no encontrado',
            'no se encontr',
            'no tiene afiliación',
            'no registra',
        ];

        $errorLower = mb_strtolower($error);
        foreach ($erroresDefinitivos as $patron) {
            if (str_contains($errorLower, $patron)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Realiza un intento individual de consulta de cédula.
     * Estrategia: usar fetch() para enviar el formulario por HTTP y recibir
     * el HTML de respuesta directamente, sin depender de nuevas pestañas.
     */
    protected function intentarConsulta(string $cedula): array
    {
        $resultado = $this->resultadoVacio($cedula);

        // Verificar driver activo
        if (!$this->driverActivo()) {
            Log::info("Reiniciando driver antes de consultar {$cedula}");
            $this->reiniciar();
        }

        try {
            // Navegar al sitio
            $this->limpiarYNavegar();
            Log::info("Página cargada para {$cedula}");

            $wait = new WebDriverWait($this->driver, 45);

            // Esperar a que el iframe aparezca
            $wait->until(WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('iframe')
            ));

            // Espera para que el iframe cargue su contenido interno
            sleep(3);

            // Buscar el iframe correcto
            $iframes = $this->driver->findElements(WebDriverBy::cssSelector('iframe'));
            $iframeEncontrado = false;

            foreach ($iframes as $iframe) {
                try {
                    $this->driver->switchTo()->frame($iframe);

                    $inputs = $this->driver->findElements(WebDriverBy::id('txtNumDoc'));
                    if (count($inputs) > 0) {
                        $iframeEncontrado = true;
                        break;
                    }

                    $this->driver->switchTo()->defaultContent();
                } catch (\Exception $e) {
                    $this->driver->switchTo()->defaultContent();
                    continue;
                }
            }

            if (!$iframeEncontrado) {
                $resultado['error'] = 'No se encontró el formulario en la página';
                $this->driver->switchTo()->defaultContent();
                return $resultado;
            }

            // Esperar a que el campo de texto esté interactivo
            $wait2 = new WebDriverWait($this->driver, 20);
            $wait2->until(WebDriverExpectedCondition::elementToBeClickable(
                WebDriverBy::id('txtNumDoc')
            ));

            // Limpiar campo e ingresar cédula
            $input = $this->driver->findElement(WebDriverBy::id('txtNumDoc'));
            $input->clear();
            sleep(1);
            $input->sendKeys($cedula);
            sleep(1);

            // Verificar que la cédula se ingresó correctamente
            $valorIngresado = $input->getAttribute('value');
            if ($valorIngresado !== $cedula) {
                Log::warning("Cédula mal ingresada. Esperado: {$cedula}, Ingresado: {$valorIngresado}. Corrigiendo...");
                $this->driver->executeScript("arguments[0].value = '';", [$input]);
                sleep(1);
                $input->sendKeys($cedula);
                sleep(1);
            }

            Log::info("Cédula {$cedula} ingresada, enviando formulario vía fetch");

            // Configurar timeout para scripts asíncronos (3 minutos)
            $this->driver->manage()->timeouts()->setScriptTimeout(180);

            // === ESTRATEGIA PRINCIPAL: Enviar formulario vía fetch() ===
            // Esto evita completamente la necesidad de abrir nuevas pestañas.
            // Recopila todos los campos del formulario ASP.NET (__VIEWSTATE, etc.)
            // y hace un POST directo, recibiendo el HTML de respuesta.
            $fetchResult = $this->driver->executeAsyncScript("
                var callback = arguments[arguments.length - 1];
                try {
                    var form = document.forms[0];
                    if (!form) { callback('FETCH_ERROR:No se encontró formulario'); return; }

                    // Recopilar todos los campos del formulario
                    var params = new URLSearchParams();
                    var inputs = form.querySelectorAll('input, select, textarea');
                    for (var i = 0; i < inputs.length; i++) {
                        var el = inputs[i];
                        if (!el.name || el.disabled) continue;
                        if (el.type === 'checkbox' || el.type === 'radio') {
                            if (el.checked) params.append(el.name, el.value);
                        } else {
                            params.append(el.name, el.value);
                        }
                    }
                    // Agregar el botón como si se hiciera click
                    params.append('btnConsultar', 'Consultar');

                    var actionUrl = form.action || window.location.href;

                    fetch(actionUrl, {
                        method: 'POST',
                        body: params.toString(),
                        credentials: 'same-origin',
                        redirect: 'follow',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        }
                    })
                    .then(function(response) {
                        if (!response.ok) {
                            return 'FETCH_ERROR:HTTP ' + response.status;
                        }
                        return response.text();
                    })
                    .then(function(html) {
                        callback(html);
                    })
                    .catch(function(err) {
                        callback('FETCH_ERROR:' + err.message);
                    });
                } catch(e) {
                    callback('FETCH_ERROR:' + e.message);
                }
            ");

            // Si fetch falló, intentar método clásico con click
            if (is_string($fetchResult) && str_starts_with($fetchResult, 'FETCH_ERROR:')) {
                $errorFetch = substr($fetchResult, 12);
                Log::warning("Fetch falló para {$cedula}: {$errorFetch}. Intentando con click...");
                return $this->intentarConsultaConClick($cedula, $resultado);
            }

            if (empty($fetchResult) || !is_string($fetchResult)) {
                Log::warning("Fetch devolvió respuesta vacía para {$cedula}. Intentando con click...");
                return $this->intentarConsultaConClick($cedula, $resultado);
            }

            $bytesRecibidos = strlen($fetchResult);
            Log::info("Respuesta fetch recibida para {$cedula} ({$bytesRecibidos} bytes)");

            // Inyectar el HTML de respuesta en el documento actual para poder usar extractores
            $this->driver->executeScript("
                document.open();
                document.write(arguments[0]);
                document.close();
            ", [$fetchResult]);
            sleep(2);

            // Ahora extraer datos del DOM inyectado
            $pageSource = $this->driver->getPageSource();

            if (str_contains($pageSource, 'GridViewBasica')) {
                Log::info("GridViewBasica encontrado en respuesta fetch para {$cedula}");
                $resultado = $this->extraerDatos($resultado);

                if (!empty($resultado['nombres']) || !empty($resultado['apellidos'])) {
                    Log::info("Datos extraídos para {$cedula}: {$resultado['nombres']} {$resultado['apellidos']}");
                } else {
                    Log::warning("GridViewBasica presente pero sin datos legibles para {$cedula}");
                    // Intentar extraer de HTML crudo como respaldo
                    $resultado = $this->extraerDatosDeHtml($fetchResult, $resultado);
                    if (!empty($resultado['nombres']) || !empty($resultado['apellidos'])) {
                        Log::info("Datos extraídos de HTML crudo para {$cedula}: {$resultado['nombres']} {$resultado['apellidos']}");
                    } else {
                        $resultado['error'] = 'No se pudieron leer los datos de la página';
                    }
                }
            } elseif (str_contains($fetchResult, 'GridViewBasica')) {
                // El document.write falló pero el HTML sí tiene datos
                Log::info("document.write no funcionó, extrayendo de HTML crudo para {$cedula}");
                $resultado = $this->extraerDatosDeHtml($fetchResult, $resultado);
                if (!empty($resultado['nombres']) || !empty($resultado['apellidos'])) {
                    Log::info("Datos extraídos de HTML crudo para {$cedula}: {$resultado['nombres']} {$resultado['apellidos']}");
                } else {
                    $resultado['error'] = 'No se pudieron leer los datos de la página';
                }
            } elseif (str_contains($fetchResult, 'No se encontraron datos') ||
                      str_contains($fetchResult, 'no registra') ||
                      str_contains($fetchResult, 'documento no encontrado')) {
                $resultado['error'] = 'No se encontraron datos para esta cédula en ADRES';
            } else {
                Log::warning("Respuesta fetch no reconocida para {$cedula} (primeros 500 chars): " . substr($fetchResult, 0, 500));
                $resultado['error'] = 'Respuesta no reconocida del servidor';
            }

            $this->driver->switchTo()->defaultContent();

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            Log::error("Error cédula {$cedula}: " . $errorMsg);

            if ($this->esErrorDeConexion($errorMsg)) {
                $this->reiniciar();
                $resultado['error'] = 'Error de conexión con el navegador';
            } else {
                $resultado['error'] = 'Error: ' . substr($errorMsg, 0, 100);
            }
        }

        return $resultado;
    }

    /**
     * Método de respaldo: intenta consulta haciendo click en el botón
     * y esperando nueva pestaña (funciona en Windows, intermitente en Linux).
     */
    protected function intentarConsultaConClick(string $cedula, array $resultado): array
    {
        try {
            Log::info("Usando método click para {$cedula}");

            // Guardar ventanas antes del click
            $handlesBefore = $this->driver->getWindowHandles();
            $mainWindow = $this->driver->getWindowHandle();

            // Click en consultar
            $btnConsultar = $this->driver->findElement(WebDriverBy::id('btnConsultar'));
            $this->driver->executeScript("arguments[0].click();", [$btnConsultar]);
            Log::info("Click ejecutado para {$cedula}");

            // Esperar nueva pestaña (hasta 90s)
            for ($i = 0; $i < 45; $i++) {
                sleep(2);
                try {
                    $handlesAfter = $this->driver->getWindowHandles();
                    if (count($handlesAfter) > count($handlesBefore)) {
                        Log::info("Nueva pestaña detectada para {$cedula} (esperó " . (($i + 1) * 2) . "s)");
                        return $this->procesarPestanaResultados($resultado, $handlesBefore, $mainWindow, $cedula);
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            Log::warning("No se abrió nueva pestaña para {$cedula} (método click)");
            $resultado['error'] = 'No se abrió pestaña de resultados';
            $this->driver->switchTo()->defaultContent();

        } catch (\Exception $e) {
            Log::error("Error en método click para {$cedula}: " . $e->getMessage());
            $resultado['error'] = 'Error: ' . substr($e->getMessage(), 0, 100);
        }

        return $resultado;
    }

    /**
     * Extrae datos de tablas ADRES directamente del HTML crudo usando DOMDocument.
     * Respaldo cuando document.write no funciona correctamente.
     */
    protected function extraerDatosDeHtml(string $html, array $resultado): array
    {
        try {
            $doc = new \DOMDocument();
            @$doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($doc);

            // Tabla 1: GridViewBasica
            $tabla = $doc->getElementById('GridViewBasica');
            if ($tabla) {
                $rows = $tabla->getElementsByTagName('tr');
                foreach ($rows as $row) {
                    $cells = $row->getElementsByTagName('td');
                    if ($cells->length >= 2) {
                        $columna = mb_strtoupper(trim($cells->item(0)->textContent));
                        $dato = trim($cells->item(1)->textContent);

                        if (str_contains($columna, 'TIPO DE IDENT') || str_contains($columna, 'TIPO IDENT')) {
                            $resultado['tipo_documento'] = $dato;
                        } elseif (str_contains($columna, 'PRIMER NOMBRE') || (str_contains($columna, 'NOMBRES') && !str_contains($columna, 'APELLIDO'))) {
                            $resultado['nombres'] = $dato;
                        } elseif (str_contains($columna, 'APELLIDOS') || str_contains($columna, 'PRIMER APELLIDO')) {
                            $resultado['apellidos'] = $dato;
                        } elseif (str_contains($columna, 'NACIMIENTO')) {
                            $resultado['fecha_nacimiento'] = $dato;
                        } elseif (str_contains($columna, 'DEPARTAMENTO')) {
                            $resultado['departamento'] = $dato;
                        } elseif (str_contains($columna, 'MUNICIPIO')) {
                            $resultado['municipio'] = $dato;
                        }
                    }
                }
            }

            // Tabla 2: GridViewAfiliacion
            $tabla2 = $doc->getElementById('GridViewAfiliacion');
            if ($tabla2) {
                $rows = $tabla2->getElementsByTagName('tr');
                $rowIndex = 0;
                foreach ($rows as $row) {
                    if ($rowIndex === 1) { // Segunda fila (datos)
                        $cells = $row->getElementsByTagName('td');
                        if ($cells->length >= 6) {
                            $resultado['estado'] = trim($cells->item(0)->textContent);
                            $resultado['entidad_eps'] = trim($cells->item(1)->textContent);
                            $resultado['regimen'] = trim($cells->item(2)->textContent);
                            $resultado['fecha_afiliacion'] = trim($cells->item(3)->textContent);
                            $resultado['fecha_finalizacion'] = trim($cells->item(4)->textContent);
                            $resultado['tipo_afiliado'] = trim($cells->item(5)->textContent);
                        }
                        break;
                    }
                    $rowIndex++;
                }
            }

        } catch (\Exception $e) {
            Log::error("Error extrayendo datos de HTML crudo: " . $e->getMessage());
        }

        return $resultado;
    }

    /**
     * Procesa resultados que aparecieron en una nueva pestaña.
     */
    protected function procesarPestanaResultados(array $resultado, array $handlesBefore, string $mainWindow, string $cedula): array
    {
        try {
            $handles = $this->driver->getWindowHandles();

            // Cambiar a la nueva pestaña
            $nuevaVentana = null;
            foreach ($handles as $handle) {
                if (!in_array($handle, $handlesBefore)) {
                    $nuevaVentana = $handle;
                    $this->driver->switchTo()->window($handle);
                    break;
                }
            }

            if (!$nuevaVentana) {
                $resultado['error'] = 'No se pudo cambiar a la pestaña de resultados';
                return $resultado;
            }

            // Esperar a que la página de resultados cargue (hasta 120s)
            $datosEncontrados = false;

            for ($i = 0; $i < 120; $i++) {
                sleep(1);
                try {
                    $pageSource = $this->driver->getPageSource();

                    if (str_contains($pageSource, 'GridViewBasica')) {
                        $datosEncontrados = true;
                        Log::info("Datos encontrados para {$cedula} (esperó {$i}s en resultados)");
                        break;
                    }

                    if (str_contains($pageSource, 'No se encontraron datos') ||
                        str_contains($pageSource, 'no registra') ||
                        str_contains($pageSource, 'No se encontr')) {
                        $resultado['error'] = 'No se encontraron datos para esta cédula en ADRES';
                        $this->cerrarPestanaYVolver($mainWindow);
                        return $resultado;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            if ($datosEncontrados) {
                sleep(2);
                $resultado = $this->extraerDatos($resultado);

                if (empty($resultado['nombres']) && empty($resultado['apellidos'])) {
                    Log::warning("Cédula {$cedula}: GridViewBasica encontrado pero no se extrajeron nombres");
                    $resultado['error'] = 'No se pudieron leer los datos de la página';
                } else {
                    Log::info("Datos extraídos para {$cedula}: {$resultado['nombres']} {$resultado['apellidos']}");
                }
            } else {
                $resultado['error'] = 'La página de resultados no cargó completamente';
            }

            $this->cerrarPestanaYVolver($mainWindow);

        } catch (\Exception $e) {
            Log::error("Error procesando resultados para {$cedula}: " . $e->getMessage());
            $resultado['error'] = 'Error leyendo resultados: ' . substr($e->getMessage(), 0, 80);

            try {
                $this->cerrarPestanaYVolver($mainWindow);
            } catch (\Exception $ex) {}
        }

        return $resultado;
    }

    /**
     * Cierra la pestaña actual y vuelve a la ventana principal de forma segura.
     */
    protected function cerrarPestanaYVolver(string $mainWindow): void
    {
        try {
            $handles = $this->driver->getWindowHandles();
            foreach ($handles as $handle) {
                if ($handle !== $mainWindow) {
                    try {
                        $this->driver->switchTo()->window($handle);
                        $this->driver->close();
                    } catch (\Exception $e) {}
                }
            }
            $this->driver->switchTo()->window($mainWindow);
            $this->driver->switchTo()->defaultContent();
        } catch (\Exception $e) {
            Log::warning("Error volviendo a ventana principal: " . $e->getMessage());
        }
    }

    protected function limpiarYNavegar(): void
    {
        try {
            // Cerrar todas las ventanas excepto una
            $handles = $this->driver->getWindowHandles();

            if (count($handles) > 1) {
                $primera = $handles[0];
                foreach ($handles as $h) {
                    if ($h !== $primera) {
                        try {
                            $this->driver->switchTo()->window($h);
                            $this->driver->close();
                        } catch (\Exception $e) {
                        }
                    }
                }
                $this->driver->switchTo()->window($primera);
            }

            // Volver al contexto principal
            $this->driver->switchTo()->defaultContent();

            // Navegar a la página (NO borrar cookies, ADRES las necesita para sesión)
            $this->driver->get($this->baseUrl);

            // Esperar a que la página cargue completamente
            $wait = new WebDriverWait($this->driver, 45);
            $wait->until(function ($driver) {
                $readyState = $driver->executeScript('return document.readyState');
                return $readyState === 'complete';
            });

        } catch (\Exception $e) {
            Log::warning("Error limpiando: " . $e->getMessage());
            $this->reiniciar();

            try {
                $this->driver->get($this->baseUrl);

                $wait = new WebDriverWait($this->driver, 45);
                $wait->until(function ($driver) {
                    return $driver->executeScript('return document.readyState') === 'complete';
                });
            } catch (\Exception $e2) {
                Log::error("Error crítico navegando después de reinicio: " . $e2->getMessage());
                throw $e2;
            }
        }
    }

    protected function driverActivo(): bool
    {
        try {
            if ($this->driver === null) return false;
            $this->driver->getWindowHandles();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Detecta si un error es de conexión con el driver/navegador.
     */
    protected function esErrorDeConexion(string $errorMsg): bool
    {
        $patronesConexion = [
            'Curl error',
            'session',
            'not reachable',
            'disconnected',
            'no such session',
            'chrome not reachable',
            'target window already closed',
            'unable to discover open pages',
        ];

        foreach ($patronesConexion as $patron) {
            if (stripos($errorMsg, $patron) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function verificarErrorFormulario(): string
    {
        try {
            // Buscar múltiples posibles elementos de error
            $selectoresError = ['Error', 'lblError', 'lblMensaje', 'divError'];

            foreach ($selectoresError as $selector) {
                try {
                    $errorElement = $this->driver->findElement(WebDriverBy::id($selector));
                    $errorText = trim($errorElement->getText());

                    if (!empty($errorText)) {
                        return $errorText;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            // Buscar por clase CSS también
            try {
                $errorElements = $this->driver->findElements(WebDriverBy::cssSelector('.error, .alert-danger, .mensaje-error'));
                foreach ($errorElements as $el) {
                    $text = trim($el->getText());
                    if (!empty($text)) {
                        return $text;
                    }
                }
            } catch (\Exception $e) {}

        } catch (\Exception $e) {}

        return 'No se encontró información para esta cédula';
    }

    protected function extraerDatos(array $resultado): array
    {
        try {
            // Tabla 1: GridViewBasica — esperar a que tenga filas
            $tablasBasica = $this->driver->findElements(WebDriverBy::id('GridViewBasica'));

            if (count($tablasBasica) > 0) {
                $rows = $tablasBasica[0]->findElements(WebDriverBy::tagName('tr'));

                foreach ($rows as $row) {
                    $cells = $row->findElements(WebDriverBy::tagName('td'));

                    if (count($cells) >= 2) {
                        $columna = mb_strtoupper(trim($cells[0]->getText()));
                        $dato = trim($cells[1]->getText());

                        if (str_contains($columna, 'TIPO DE IDENT') || str_contains($columna, 'TIPO IDENT')) {
                            $resultado['tipo_documento'] = $dato;
                        } elseif (str_contains($columna, 'PRIMER NOMBRE') || (str_contains($columna, 'NOMBRES') && !str_contains($columna, 'APELLIDO'))) {
                            $resultado['nombres'] = $dato;
                        } elseif (str_contains($columna, 'APELLIDOS') || str_contains($columna, 'PRIMER APELLIDO')) {
                            $resultado['apellidos'] = $dato;
                        } elseif (str_contains($columna, 'NACIMIENTO')) {
                            $resultado['fecha_nacimiento'] = $dato;
                        } elseif (str_contains($columna, 'DEPARTAMENTO')) {
                            $resultado['departamento'] = $dato;
                        } elseif (str_contains($columna, 'MUNICIPIO')) {
                            $resultado['municipio'] = $dato;
                        }
                    }
                }
            }

            // Tabla 2: GridViewAfiliacion
            $tablasAfiliacion = $this->driver->findElements(WebDriverBy::id('GridViewAfiliacion'));

            if (count($tablasAfiliacion) > 0) {
                $rows = $tablasAfiliacion[0]->findElements(WebDriverBy::tagName('tr'));

                if (count($rows) >= 2) {
                    $cells = $rows[1]->findElements(WebDriverBy::tagName('td'));

                    if (count($cells) >= 6) {
                        $resultado['estado'] = trim($cells[0]->getText());
                        $resultado['entidad_eps'] = trim($cells[1]->getText());
                        $resultado['regimen'] = trim($cells[2]->getText());
                        $resultado['fecha_afiliacion'] = trim($cells[3]->getText());
                        $resultado['fecha_finalizacion'] = trim($cells[4]->getText());
                        $resultado['tipo_afiliado'] = trim($cells[5]->getText());
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error("Error extrayendo datos: " . $e->getMessage());
        }

        return $resultado;
    }

    /**
     * Genera un resultado vacío para una cédula.
     */
    protected function resultadoVacio(string $cedula): array
    {
        return [
            'cedula' => $cedula,
            'tipo_documento' => '',
            'nombres' => '',
            'apellidos' => '',
            'fecha_nacimiento' => '',
            'departamento' => '',
            'municipio' => '',
            'estado' => '',
            'entidad_eps' => '',
            'regimen' => '',
            'fecha_afiliacion' => '',
            'fecha_finalizacion' => '',
            'tipo_afiliado' => '',
            'error' => '',
        ];
    }

    /**
     * Espera con seguridad, usando usleep para mayor precisión.
     */
    protected function esperarConSeguridad(int $segundos): void
    {
        usleep($segundos * 1_000_000);
    }

    protected function reiniciar(): void
    {
        try {
            if ($this->driver) {
                $this->driver->quit();
            }
        } catch (\Exception $e) {}

        $this->driver = null;
        sleep(2);
        $this->initDriver();
    }

    public function cerrar(): void
    {
        try {
            if ($this->driver) {
                $this->driver->quit();
            }
        } catch (\Exception $e) {}
        $this->driver = null;
    }

    public function __destruct()
    {
        $this->cerrar();
    }
}
