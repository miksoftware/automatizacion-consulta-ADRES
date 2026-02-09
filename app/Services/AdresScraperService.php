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
    
    public function __construct()
    {
        $this->initDriver();
    }

    protected function initDriver(): void
    {
        $options = new ChromeOptions();
        $options->addArguments([
            '--headless=new',
            '--incognito',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--window-size=1920,1080',
            '--disable-blink-features=AutomationControlled',
            '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
        ]);
        
        $options->setExperimentalOption('excludeSwitches', ['enable-automation']);
        $options->setExperimentalOption('useAutomationExtension', false);

        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

        $this->driver = RemoteWebDriver::create(
            'http://localhost:9515',
            $capabilities,
            60000,
            60000
        );
        
        $this->driver->executeScript("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})");
    }

    public function consultarCedula(string $cedula): array
    {
        $resultado = [
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

        // Verificar driver activo
        if (!$this->driverActivo()) {
            Log::info("Reiniciando driver antes de consultar {$cedula}");
            $this->reiniciar();
        }

        try {
            // Cerrar todas las ventanas extra y navegar fresh
            $this->limpiarYNavegar();
            
            $wait = new WebDriverWait($this->driver, 30);
            
            // Esperar iframe
            $wait->until(WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('iframe')
            ));
            
            sleep(2);
            
            $iframe = $this->driver->findElement(WebDriverBy::cssSelector('iframe'));
            $this->driver->switchTo()->frame($iframe);
            
            // Esperar formulario
            $wait->until(WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::id('txtNumDoc')
            ));
            
            sleep(1);
            
            // Limpiar e ingresar cédula
            $input = $this->driver->findElement(WebDriverBy::id('txtNumDoc'));
            $input->clear();
            sleep(1);
            $input->sendKeys($cedula);
            
            Log::info("Consultando cédula: {$cedula}");
            
            // Contar ventanas antes del click
            $handlesBefore = $this->driver->getWindowHandles();
            $mainWindow = $this->driver->getWindowHandle();
            
            // Click en consultar
            $btnConsultar = $this->driver->findElement(WebDriverBy::id('btnConsultar'));
            $this->driver->executeScript("arguments[0].click();", [$btnConsultar]);
            
            // Esperar nueva pestaña
            $nuevaPestana = false;
            for ($i = 0; $i < 10; $i++) {
                sleep(1);
                $handlesAfter = $this->driver->getWindowHandles();
                if (count($handlesAfter) > count($handlesBefore)) {
                    $nuevaPestana = true;
                    Log::info("Nueva pestaña detectada para {$cedula}");
                    break;
                }
            }
            
            if ($nuevaPestana) {
                $handles = $this->driver->getWindowHandles();
                
                // Cambiar a la nueva pestaña
                foreach ($handles as $handle) {
                    if (!in_array($handle, $handlesBefore)) {
                        $this->driver->switchTo()->window($handle);
                        break;
                    }
                }
                
                sleep(3);
                
                // Verificar contenido
                $pageSource = $this->driver->getPageSource();
                
                if (str_contains($pageSource, 'GridViewBasica')) {
                    $resultado = $this->extraerDatos($resultado);
                    Log::info("Datos extraídos para {$cedula}: {$resultado['nombres']}");
                } else {
                    $resultado['error'] = 'Cédula no encontrada en ADRES';
                }
                
                // Cerrar pestaña de resultados
                $this->driver->close();
                
                // Volver a la ventana principal
                $this->driver->switchTo()->window($mainWindow);
                $this->driver->switchTo()->defaultContent();
                
            } else {
                $resultado['error'] = $this->verificarErrorFormulario();
                $this->driver->switchTo()->defaultContent();
            }
            
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            Log::error("Error cédula {$cedula}: " . $errorMsg);
            
            if (str_contains($errorMsg, 'Curl error') || str_contains($errorMsg, 'session')) {
                $this->reiniciar();
                $resultado['error'] = 'Error de conexión, reintentar';
            } else {
                $resultado['error'] = 'Error: ' . substr($errorMsg, 0, 80);
            }
        }

        return $resultado;
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
                        $this->driver->switchTo()->window($h);
                        $this->driver->close();
                    }
                }
                $this->driver->switchTo()->window($primera);
            }
            
            // Volver al contexto principal
            $this->driver->switchTo()->defaultContent();
            
            // Navegar a la página (esto resetea todo)
            $this->driver->get($this->baseUrl);
            
        } catch (\Exception $e) {
            Log::warning("Error limpiando: " . $e->getMessage());
            $this->reiniciar();
            $this->driver->get($this->baseUrl);
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

    protected function verificarErrorFormulario(): string
    {
        try {
            $errorSpan = $this->driver->findElement(WebDriverBy::id('Error'));
            $errorText = trim($errorSpan->getText());
            
            if (!empty($errorText)) {
                return $errorText;
            }
        } catch (\Exception $e) {}
        
        return 'Cédula no encontrada en ADRES';
    }

    protected function extraerDatos(array $resultado): array
    {
        try {
            // Tabla 1: GridViewBasica
            $tablasBasica = $this->driver->findElements(WebDriverBy::id('GridViewBasica'));
            
            if (count($tablasBasica) > 0) {
                $rows = $tablasBasica[0]->findElements(WebDriverBy::tagName('tr'));
                
                foreach ($rows as $row) {
                    $cells = $row->findElements(WebDriverBy::tagName('td'));
                    
                    if (count($cells) >= 2) {
                        $columna = mb_strtoupper(trim($cells[0]->getText()));
                        $dato = trim($cells[1]->getText());
                        
                        if (str_contains($columna, 'TIPO DE IDENT')) {
                            $resultado['tipo_documento'] = $dato;
                        } elseif (str_contains($columna, 'NOMBRES')) {
                            $resultado['nombres'] = $dato;
                        } elseif (str_contains($columna, 'APELLIDOS')) {
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
