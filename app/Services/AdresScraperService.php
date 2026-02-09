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
            '--incognito',
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
                '--no-zygote',
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
                '--remote-debugging-port=0',
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
            // Navegar al sitio (siempre recargar para estado limpio)
            $this->limpiarYNavegar();

            $wait = new WebDriverWait($this->driver, 45);

            // Esperar a que el iframe aparezca
            $wait->until(WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('iframe')
            ));

            // Espera para que el iframe cargue su contenido
            sleep(2);

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

            // Limpiar campo con JavaScript e ingresar cédula
            $input = $this->driver->findElement(WebDriverBy::id('txtNumDoc'));
            $this->driver->executeScript("arguments[0].value = '';", [$input]);
            usleep(300000);
            $input->sendKeys($cedula);
            usleep(300000);

            // Verificar que la cédula se ingresó correctamente
            $valorIngresado = $input->getAttribute('value');
            if ($valorIngresado !== $cedula) {
                Log::warning("Cédula mal ingresada. Esperado: {$cedula}, Ingresado: {$valorIngresado}. Corrigiendo...");
                $this->driver->executeScript("arguments[0].value = '';", [$input]);
                usleep(300000);
                $input->sendKeys($cedula);
                usleep(300000);
            }

            Log::info("Consultando cédula: {$cedula}");

            // Guardar ventanas antes del click
            $handlesBefore = $this->driver->getWindowHandles();
            $mainWindow = $this->driver->getWindowHandle();

            // Click en consultar
            $btnConsultar = $this->driver->findElement(WebDriverBy::id('btnConsultar'));
            $this->driver->executeScript("arguments[0].click();", [$btnConsultar]);

            // Esperar a que abra nueva pestaña — polling cada 1s, hasta 60s
            $nuevaPestana = false;

            for ($i = 0; $i < 60; $i++) {
                sleep(1);
                try {
                    $handlesAfter = $this->driver->getWindowHandles();
                    if (count($handlesAfter) > count($handlesBefore)) {
                        $nuevaPestana = true;
                        Log::info("Nueva pestaña detectada para {$cedula} (esperó {$i}s)");
                        break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            if ($nuevaPestana) {
                $resultado = $this->procesarPestanaResultados($resultado, $handlesBefore, $mainWindow, $cedula);
            } else {
                // No se abrió pestaña — revisar si hay error en el formulario
                $errorFormulario = $this->verificarErrorFormulario();
                $resultado['error'] = $errorFormulario;

                $this->driver->switchTo()->defaultContent();
            }

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
     * Procesa la pestaña de resultados con esperas dinámicas.
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

            // Esperar a que la página de resultados cargue (hasta 45s)
            $datosEncontrados = false;

            for ($i = 0; $i < 45; $i++) {
                sleep(1);
                try {
                    $pageSource = $this->driver->getPageSource();

                    if (str_contains($pageSource, 'GridViewBasica')) {
                        $datosEncontrados = true;
                        Log::info("Datos encontrados para {$cedula} (esperó {$i}s en resultados)");
                        break;
                    }

                    // Verificar si la página muestra un mensaje de "no encontrado"
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
                // Esperar a que las tablas terminen de renderizar
                sleep(2);

                $resultado = $this->extraerDatos($resultado);

                // Verificar que realmente se extrajeron datos
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
            } catch (\Exception $ex) {
            }
        }

        return $resultado;
    }

    /**
     * Cierra la pestaña actual y vuelve a la ventana principal de forma segura.
     */
    protected function cerrarPestanaYVolver(string $mainWindow): void
    {
        try {
            // Cerrar todas las pestañas excepto la principal
            $handles = $this->driver->getWindowHandles();
            foreach ($handles as $handle) {
                if ($handle !== $mainWindow) {
                    try {
                        $this->driver->switchTo()->window($handle);
                        $this->driver->close();
                    } catch (\Exception $e) {
                        // Pestaña ya cerrada, ignorar
                    }
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
                            // Pestaña ya cerrada, ignorar
                        }
                    }
                }
                $this->driver->switchTo()->window($primera);
            }

            // Volver al contexto principal
            $this->driver->switchTo()->defaultContent();

            // Limpiar cookies y cache para evitar problemas de sesión
            $this->driver->manage()->deleteAllCookies();

            // Navegar a la página (esto resetea todo)
            $this->driver->get($this->baseUrl);

            // Esperar a que la página cargue completamente
            $wait = new WebDriverWait($this->driver, 30);
            $wait->until(function ($driver) {
                $readyState = $driver->executeScript('return document.readyState');
                return $readyState === 'complete';
            });

        } catch (\Exception $e) {
            Log::warning("Error limpiando: " . $e->getMessage());
            $this->reiniciar();

            try {
                $this->driver->get($this->baseUrl);

                $wait = new WebDriverWait($this->driver, 30);
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
