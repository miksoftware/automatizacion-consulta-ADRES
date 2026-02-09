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
            '--incognito',
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
     * Estrategia en 2 pasos vía fetch():
     *   1) POST formulario → obtener URL con tokenId de window.open()
     *   2) GET a esa URL → obtener HTML con GridViewBasica/GridViewAfiliacion
     * Esto elimina completamente la dependencia de nuevas pestañas.
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

            sleep(3);

            // Buscar el iframe correcto
            $iframes = $this->driver->findElements(WebDriverBy::cssSelector('iframe'));
            $iframeEncontrado = false;
            $iframeSrc = '';

            foreach ($iframes as $iframe) {
                try {
                    // Capturar src del iframe antes de entrar
                    $iframeSrc = $iframe->getAttribute('src') ?? '';
                    $this->driver->switchTo()->frame($iframe);

                    $inputs = $this->driver->findElements(WebDriverBy::id('txtNumDoc'));
                    if (count($inputs) > 0) {
                        $iframeEncontrado = true;
                        // Capturar la URL base del iframe desde dentro
                        $iframeSrc = $this->driver->executeScript('return window.location.href');
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

            Log::info("Iframe URL base: {$iframeSrc}");

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
                Log::warning("Cédula mal ingresada para {$cedula}. Corrigiendo...");
                $this->driver->executeScript("arguments[0].value = '';", [$input]);
                sleep(1);
                $input->sendKeys($cedula);
                sleep(1);
            }

            Log::info("Cédula {$cedula} ingresada, enviando formulario vía fetch (paso 1)");

            // Configurar timeout para scripts asíncronos
            $this->driver->manage()->timeouts()->setScriptTimeout(180);

            // === PASO 1: POST formulario, obtener respuesta con window.open URL ===
            $fetchResult = $this->driver->executeAsyncScript("
                var callback = arguments[arguments.length - 1];
                try {
                    var form = document.forms[0];
                    if (!form) { callback('FETCH_ERROR:No se encontró formulario'); return; }

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
                    params.append('btnConsultar', 'Consultar');

                    var actionUrl = form.action || window.location.href;

                    fetch(actionUrl, {
                        method: 'POST',
                        body: params.toString(),
                        credentials: 'same-origin',
                        redirect: 'follow',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                    })
                    .then(function(r) { return r.text(); })
                    .then(function(html) { callback(html); })
                    .catch(function(err) { callback('FETCH_ERROR:' + err.message); });
                } catch(e) {
                    callback('FETCH_ERROR:' + e.message);
                }
            ");

            if (is_string($fetchResult) && str_starts_with($fetchResult, 'FETCH_ERROR:')) {
                $resultado['error'] = 'Error enviando formulario: ' . substr($fetchResult, 12);
                $this->driver->switchTo()->defaultContent();
                return $resultado;
            }

            if (empty($fetchResult) || !is_string($fetchResult)) {
                $resultado['error'] = 'Respuesta vacía del servidor';
                $this->driver->switchTo()->defaultContent();
                return $resultado;
            }

            Log::info("Paso 1 completado para {$cedula} (" . strlen($fetchResult) . " bytes)");

            // === PASO 2: Extraer URL de window.open() y hacer segundo fetch ===
            // La respuesta contiene: window.open('RespuestaConsulta.aspx?tokenId=XXX', ...)
            if (preg_match("/window\\.open\\(['\"]([^'\"]+)['\"]/", $fetchResult, $matches)) {
                $resultUrl = $matches[1];
                Log::info("URL de resultados encontrada para {$cedula}: {$resultUrl}");

                // Resolver URL relativa contra la URL base del iframe
                $fetchResult2 = $this->driver->executeAsyncScript("
                    var callback = arguments[arguments.length - 1];
                    try {
                        var relUrl = arguments[0];
                        // Construir URL absoluta
                        var baseUrl = window.location.href;
                        var basePath = baseUrl.substring(0, baseUrl.lastIndexOf('/') + 1);
                        var fullUrl = basePath + relUrl;

                        fetch(fullUrl, {
                            method: 'GET',
                            credentials: 'same-origin'
                        })
                        .then(function(r) { return r.text(); })
                        .then(function(html) { callback(html); })
                        .catch(function(err) { callback('FETCH_ERROR:' + err.message); });
                    } catch(e) {
                        callback('FETCH_ERROR:' + e.message);
                    }
                ", [$resultUrl]);

                if (is_string($fetchResult2) && str_starts_with($fetchResult2, 'FETCH_ERROR:')) {
                    Log::warning("Fetch paso 2 falló para {$cedula}: " . substr($fetchResult2, 12));
                    $resultado['error'] = 'Error obteniendo resultados';
                    $this->driver->switchTo()->defaultContent();
                    return $resultado;
                }

                if (!empty($fetchResult2) && is_string($fetchResult2)) {
                    Log::info("Paso 2 completado para {$cedula} (" . strlen($fetchResult2) . " bytes)");

                    // Parsear el HTML de resultados
                    $resultado = $this->extraerDatosDeHtml($fetchResult2, $resultado);

                    if (!empty($resultado['nombres']) || !empty($resultado['apellidos'])) {
                        Log::info("Datos extraídos para {$cedula}: {$resultado['nombres']} {$resultado['apellidos']}");
                    } elseif (str_contains($fetchResult2, 'No se encontraron datos') ||
                              str_contains($fetchResult2, 'no registra')) {
                        $resultado['error'] = 'No se encontraron datos para esta cédula en ADRES';
                    } else {
                        Log::warning("Sin datos reconocidos para {$cedula} (primeros 500): " . substr($fetchResult2, 0, 500));
                        $resultado['error'] = 'No se pudieron leer los datos de la respuesta';
                    }
                } else {
                    $resultado['error'] = 'Respuesta vacía en paso 2';
                }
            } elseif (str_contains($fetchResult, 'GridViewBasica')) {
                // Caso: la respuesta del POST ya trae los datos directamente
                Log::info("Datos encontrados directamente en respuesta del POST para {$cedula}");
                $resultado = $this->extraerDatosDeHtml($fetchResult, $resultado);
                if (empty($resultado['nombres']) && empty($resultado['apellidos'])) {
                    $resultado['error'] = 'No se pudieron leer los datos de la página';
                } else {
                    Log::info("Datos extraídos para {$cedula}: {$resultado['nombres']} {$resultado['apellidos']}");
                }
            } elseif (str_contains($fetchResult, 'No se encontraron datos') ||
                      str_contains($fetchResult, 'no registra') ||
                      str_contains($fetchResult, 'documento no encontrado')) {
                $resultado['error'] = 'No se encontraron datos para esta cédula en ADRES';
            } else {
                Log::warning("Respuesta paso 1 no reconocida para {$cedula}: " . substr($fetchResult, 0, 300));
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
     * Extrae datos de tablas ADRES directamente del HTML crudo usando DOMDocument.
     * Funciona tanto para la página de resultados como para respuestas directas.
     */
    protected function extraerDatosDeHtml(string $html, array $resultado): array
    {
        try {
            $doc = new \DOMDocument();
            @$doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

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
                    if ($rowIndex === 1) {
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
            Log::error("Error extrayendo datos de HTML: " . $e->getMessage());
        }

        return $resultado;
    }

    protected function limpiarYNavegar(): void
    {
        try {
            $handles = $this->driver->getWindowHandles();

            if (count($handles) > 1) {
                $primera = $handles[0];
                foreach ($handles as $h) {
                    if ($h !== $primera) {
                        try {
                            $this->driver->switchTo()->window($h);
                            $this->driver->close();
                        } catch (\Exception $e) {}
                    }
                }
                $this->driver->switchTo()->window($primera);
            }

            $this->driver->switchTo()->defaultContent();
            $this->driver->get($this->baseUrl);

            $wait = new WebDriverWait($this->driver, 45);
            $wait->until(function ($driver) {
                return $driver->executeScript('return document.readyState') === 'complete';
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

    protected function esErrorDeConexion(string $errorMsg): bool
    {
        $patronesConexion = [
            'Curl error', 'session', 'not reachable', 'disconnected',
            'no such session', 'chrome not reachable', 'target window already closed',
            'unable to discover open pages',
        ];

        foreach ($patronesConexion as $patron) {
            if (stripos($errorMsg, $patron) !== false) {
                return true;
            }
        }

        return false;
    }

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
