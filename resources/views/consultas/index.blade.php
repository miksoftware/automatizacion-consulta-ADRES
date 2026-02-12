<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Consulta ADRES - Sistema de Consulta Masiva</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .log-scroll { max-height: 320px; overflow-y: auto; }
        .log-scroll::-webkit-scrollbar { width: 6px; }
        .log-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
        .log-scroll::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 3px; }
        .fade-in { animation: fadeIn 0.3s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes pulse-soft { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        .pulse-soft { animation: pulse-soft 2s ease-in-out infinite; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        
        <!-- Header con menú -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-lg shadow-lg p-6 mb-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-3">
                        <i class="fas fa-hospital-user"></i>
                        Sistema de Consulta Masiva  del Sistema de Seguridad Social
                    </h1>
                    <p class="text-blue-100 mt-1">Consulta información de afiliación al sistema de salud colombiano</p>
                </div>
            </div>
            <!-- Menú de navegación -->
            <div class="flex gap-4 mt-4 border-t border-blue-500 pt-3">
                <a href="{{ route('consultas.index') }}" class="text-white bg-blue-700 transition flex items-center gap-2 text-sm font-medium px-3 py-1 rounded">
                    <i class="fas fa-upload"></i> Consulta Masiva
                </a>
                <a href="{{ route('consultas.consultar') }}" class="text-blue-200 hover:text-white transition flex items-center gap-2 text-sm font-medium px-3 py-1 rounded hover:bg-blue-700">
                    <i class="fas fa-search"></i> Consultar Cédula
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Panel de Carga -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-lg p-6 sticky top-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-upload text-blue-600"></i>
                        Nueva Consulta
                    </h2>

                    <!-- PASO 1: Formulario de carga -->
                    <div id="paso-subir">
                        <form id="formValidar" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Archivo con cédulas
                                </label>
                                <div id="dropZone" class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hover:border-blue-500 transition cursor-pointer" onclick="document.getElementById('archivo').click()">
                                    <i class="fas fa-file-excel text-4xl text-gray-400 mb-2"></i>
                                    <p class="text-sm text-gray-600">Click para seleccionar archivo</p>
                                    <p class="text-xs text-gray-400 mt-1">Excel o CSV (máx. 10MB)</p>
                                </div>
                                <input type="file" name="archivo" id="archivo" accept=".xlsx,.xls,.csv" required class="hidden" onchange="mostrarArchivo(this)">
                                <p id="nombreArchivo" class="mt-2 text-sm text-blue-600 hidden"></p>
                            </div>
                            <button type="submit" id="btnValidar" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg transition flex items-center justify-center gap-2">
                                <i class="fas fa-search"></i>
                                Validar Archivo
                            </button>
                        </form>
                    </div>

                    <!-- PASO 2: Confirmación con tiempo estimado -->
                    <div id="paso-confirmar" class="hidden fade-in">
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                            <div class="flex items-center gap-2 mb-3">
                                <i class="fas fa-info-circle text-blue-600"></i>
                                <span class="font-semibold text-blue-800">Resumen del archivo</span>
                            </div>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Archivo:</span>
                                    <span class="font-medium text-gray-800 truncate max-w-[150px]" id="confirmar-archivo"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Cédulas encontradas:</span>
                                    <span class="font-bold text-blue-700 text-lg" id="confirmar-total"></span>
                                </div>
                                <hr class="border-blue-200">
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-600">Tiempo estimado:</span>
                                    <span class="font-bold text-orange-600 text-lg" id="confirmar-tiempo"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Aviso para archivos grandes -->
                        <div id="aviso-grande" class="hidden bg-yellow-50 border border-yellow-300 rounded-lg p-3 mb-4 text-sm">
                            <div class="flex items-start gap-2">
                                <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
                                <div>
                                    <p class="font-medium text-yellow-800">Archivo con muchas cédulas</p>
                                    <p class="text-yellow-700 mt-1">El proceso puede tomar bastante tiempo. Puedes dejar esta ventana abierta y continuar con otras tareas mientras se ejecuta la consulta.</p>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <button id="btnIniciar" onclick="iniciarProceso()" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-3 px-4 rounded-lg transition flex items-center justify-center gap-2">
                                <i class="fas fa-play"></i>
                                Iniciar Consulta
                            </button>
                            <button onclick="cancelarConfirmacion()" class="w-full text-gray-600 hover:text-gray-800 font-medium py-2 text-sm">
                                <i class="fas fa-arrow-left mr-1"></i>Cambiar archivo
                            </button>
                        </div>
                    </div>

                    <!-- PASO 3: Progreso en tiempo real -->
                    <div id="paso-progreso" class="hidden fade-in">
                        <!-- Barra de progreso principal -->
                        <div class="mb-4">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-sm font-medium text-gray-700" id="texto-progreso">Iniciando...</span>
                                <span class="text-sm font-bold text-blue-600" id="porcentaje-progreso">0%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-3 mb-1">
                                <div id="barra-progreso" class="bg-blue-600 h-3 rounded-full transition-all duration-500 ease-out" style="width: 0%"></div>
                            </div>
                            <div class="flex justify-between text-xs text-gray-500">
                                <span id="contador-progreso">0 / 0</span>
                                <span id="exitosas-fallidas"></span>
                            </div>
                        </div>

                        <!-- Info -->
                        <div class="bg-blue-50 rounded-lg p-3 mb-4 text-sm">
                            <div class="flex items-start gap-2">
                                <i class="fas fa-info-circle text-blue-600 mt-0.5"></i>
                                <div>
                                    <p class="font-medium text-blue-800">Procesando en segundo plano</p>
                                    <p class="text-blue-700 mt-1">Puedes cerrar esta página. El proceso continuará automáticamente. Vuelve cuando quieras para ver el progreso.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Estado del proceso -->
                        <div class="bg-gray-50 rounded-lg p-3 mb-4">
                            <div class="text-xs text-gray-500 mb-1">Estado actual:</div>
                            <div class="font-bold text-gray-800 pulse-soft" id="estado-actual">Enviado a la cola...</div>
                        </div>
                    </div>

                    <!-- PASO 4: Resultado final -->
                    <div id="paso-resultado" class="hidden fade-in text-center">
                        <div class="mb-4">
                            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-check text-3xl text-green-600"></i>
                            </div>
                            <p class="text-lg font-medium text-gray-800">¡Consulta completada!</p>
                        </div>

                        <!-- Resumen final -->
                        <div class="bg-gray-50 rounded-lg p-4 mb-4 text-sm">
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <div class="text-2xl font-bold text-blue-600" id="res-total">0</div>
                                    <div class="text-xs text-gray-500">Total</div>
                                </div>
                                <div>
                                    <div class="text-2xl font-bold text-green-600" id="res-exitosas">0</div>
                                    <div class="text-xs text-gray-500">Exitosas</div>
                                </div>
                                <div>
                                    <div class="text-2xl font-bold text-red-500" id="res-fallidas">0</div>
                                    <div class="text-xs text-gray-500">Fallidas</div>
                                </div>
                            </div>
                            <div class="mt-3 pt-3 border-t border-gray-200">
                                <span class="text-gray-500">Tiempo total:</span>
                                <span class="font-bold text-gray-700" id="res-tiempo"></span>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <a id="btnDescargarExcel" href="#" class="block w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2.5 px-4 rounded-lg transition">
                                <i class="fas fa-file-excel mr-2"></i>Descargar Excel
                            </a>
                            <a id="btnDescargarCsv" href="#" class="block w-full bg-gray-600 hover:bg-gray-700 text-white font-medium py-2.5 px-4 rounded-lg transition">
                                <i class="fas fa-file-csv mr-2"></i>Descargar CSV
                            </a>
                            <button onclick="reiniciar()" class="w-full text-blue-600 hover:text-blue-800 font-medium py-2">
                                <i class="fas fa-plus mr-1"></i>Nueva consulta
                            </button>
                        </div>
                    </div>

                    <!-- Error -->
                    <div id="paso-error" class="hidden fade-in text-center">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-times text-3xl text-red-600"></i>
                        </div>
                        <p class="text-lg font-medium text-red-600 mb-2" id="mensajeError">Error</p>
                        <p class="text-sm text-gray-500 mb-4" id="detalleError"></p>
                        <button onclick="reiniciar()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition">
                            Intentar de nuevo
                        </button>
                    </div>

                    <!-- Loading inline (validando archivo) -->
                    <div id="paso-validando" class="hidden fade-in text-center py-4">
                        <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-blue-600 border-t-transparent mb-3"></div>
                        <p class="text-gray-600 text-sm">Leyendo archivo...</p>
                    </div>
                </div>
            </div>

            <!-- Historial -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-history text-blue-600"></i>
                        Historial de Consultas
                    </h2>

                    @if(session('success'))
                        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if($consultas->isEmpty())
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-inbox text-4xl mb-3"></i>
                            <p>No hay consultas registradas</p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead>
                                    <tr class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <th class="px-4 py-3">Archivo</th>
                                        <th class="px-4 py-3">Cédulas</th>
                                        <th class="px-4 py-3">Estado</th>
                                        <th class="px-4 py-3">Fechas</th>
                                        <th class="px-4 py-3">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    @foreach($consultas as $c)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <div class="text-sm font-medium text-gray-900 truncate max-w-[150px]" title="{{ $c->archivo_entrada }}">
                                                {{ $c->archivo_entrada }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-sm text-gray-600">
                                                <span class="text-green-600">{{ $c->exitosas }}</span> / 
                                                <span class="text-red-600">{{ $c->fallidas }}</span> / 
                                                {{ $c->total_cedulas }}
                                            </div>
                                            <div class="text-xs text-gray-400">éxito / error / total</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            @if($c->estado === 'completado')
                                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">
                                                    <i class="fas fa-check mr-1"></i>Completado
                                                </span>
                                            @elseif($c->estado === 'procesando')
                                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">
                                                    <i class="fas fa-spinner fa-spin mr-1"></i>Procesando
                                                </span>
                                            @elseif($c->estado === 'error')
                                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">
                                                    <i class="fas fa-times mr-1"></i>Error
                                                </span>
                                            @else
                                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                                    Pendiente
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-500">
                                            <div><i class="fas fa-upload mr-1"></i>{{ $c->created_at->format('d/m/Y H:i') }}</div>
                                            @if($c->fecha_generacion)
                                                <div><i class="fas fa-cog mr-1"></i>{{ $c->fecha_generacion->format('d/m/Y H:i') }}</div>
                                            @endif
                                            @if($c->fecha_descarga)
                                                <div><i class="fas fa-download mr-1"></i>{{ $c->fecha_descarga->format('d/m/Y H:i') }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-1">
                                                @if($c->archivo_entrada_path)
                                                    <a href="{{ route('consultas.descargar.original', $c) }}" 
                                                       class="p-2 text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded" 
                                                       title="Descargar archivo original">
                                                        <i class="fas fa-file-upload"></i>
                                                    </a>
                                                @endif
                                                @if($c->archivo_salida)
                                                    <a href="{{ route('consultas.descargar.resultado', $c) }}" 
                                                       class="p-2 text-gray-600 hover:text-green-600 hover:bg-green-50 rounded" 
                                                       title="Descargar Excel">
                                                        <i class="fas fa-file-excel"></i>
                                                    </a>
                                                    <a href="{{ route('consultas.descargar.resultado', ['consulta' => $c, 'formato' => 'csv']) }}" 
                                                       class="p-2 text-gray-600 hover:text-green-600 hover:bg-green-50 rounded" 
                                                       title="Descargar CSV">
                                                        <i class="fas fa-file-csv"></i>
                                                    </a>
                                                @endif
                                                @if(in_array($c->estado, ['error', 'procesando']))
                                                    <button onclick="reprocesar({{ $c->id }})" 
                                                       class="p-2 text-gray-600 hover:text-orange-600 hover:bg-orange-50 rounded" 
                                                       title="Reprocesar">
                                                        <i class="fas fa-redo"></i>
                                                    </button>
                                                @endif
                                                <form action="{{ route('consultas.eliminar', $c) }}" method="POST" class="inline" onsubmit="return confirm('¿Eliminar este registro?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="p-2 text-gray-600 hover:text-red-600 hover:bg-red-50 rounded" title="Eliminar">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginación -->
                        <div class="mt-4">
                            {{ $consultas->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center text-gray-500 text-sm mt-6">
            <p>Sistema de consulta al portal ADRES Colombia</p>
        </div>
    </div>

    <script>
        // ─── Estado global ──────────────────────────────────────────────
        let archivoRuta = null;
        let archivoNombre = null;
        let pollingInterval = null;
        let consultaActualId = null;

        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        // ─── Elementos DOM ──────────────────────────────────────────────
        const pasos = {
            subir: document.getElementById('paso-subir'),
            validando: document.getElementById('paso-validando'),
            confirmar: document.getElementById('paso-confirmar'),
            progreso: document.getElementById('paso-progreso'),
            resultado: document.getElementById('paso-resultado'),
            error: document.getElementById('paso-error'),
        };

        function mostrarPaso(nombre) {
            Object.values(pasos).forEach(p => p.classList.add('hidden'));
            pasos[nombre].classList.remove('hidden');
        }

        // ─── PASO 1: Selección de archivo ───────────────────────────────
        function mostrarArchivo(input) {
            const nombre = document.getElementById('nombreArchivo');
            if (input.files.length > 0) {
                nombre.textContent = input.files[0].name;
                nombre.classList.remove('hidden');
            }
        }

        document.getElementById('formValidar').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fileInput = document.getElementById('archivo');
            if (!fileInput.files.length) return;

            mostrarPaso('validando');

            const formData = new FormData();
            formData.append('archivo', fileInput.files[0]);
            formData.append('_token', csrf);

            try {
                const response = await fetch('/validar', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-CSRF-TOKEN': csrf }
                });

                const data = await response.json();

                if (!data.ok) {
                    mostrarError(data.error);
                    return;
                }

                // Guardar datos para el paso de procesamiento
                archivoRuta = data.archivo_ruta;
                archivoNombre = data.archivo_nombre;

                // Mostrar confirmación
                document.getElementById('confirmar-archivo').textContent = data.archivo_nombre;
                document.getElementById('confirmar-total').textContent = data.total_cedulas;
                document.getElementById('confirmar-tiempo').textContent = '~' + data.tiempo_estimado_texto;

                // Mostrar aviso para archivos grandes (> 20 cédulas)
                const avisoGrande = document.getElementById('aviso-grande');
                if (data.total_cedulas > 20) {
                    avisoGrande.classList.remove('hidden');
                } else {
                    avisoGrande.classList.add('hidden');
                }

                mostrarPaso('confirmar');

            } catch (err) {
                mostrarError('Error de conexión: ' + err.message);
            }
        });

        // ─── PASO 2: Confirmación ──────────────────────────────────────
        function cancelarConfirmacion() {
            archivoRuta = null;
            archivoNombre = null;
            mostrarPaso('subir');
        }

        // ─── PASO 3: Procesamiento con Polling ─────────────────────────
        async function iniciarProceso() {
            mostrarPaso('progreso');
            document.getElementById('estado-actual').textContent = 'Enviando a la cola de procesamiento...';

            const formData = new FormData();
            formData.append('_token', csrf);
            formData.append('archivo_ruta', archivoRuta);
            formData.append('archivo_nombre', archivoNombre);

            try {
                const response = await fetch('/procesar', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-CSRF-TOKEN': csrf }
                });

                const data = await response.json();

                if (!data.ok) {
                    mostrarError(data.error || 'Error al enviar el archivo.');
                    return;
                }

                consultaActualId = data.consulta_id;
                document.getElementById('estado-actual').textContent = 'En cola de procesamiento...';
                document.getElementById('contador-progreso').textContent = `0 / ${data.total}`;

                // Iniciar polling cada 3 segundos
                iniciarPolling(data.consulta_id);

            } catch (err) {
                mostrarError('Error de conexión: ' + err.message);
            }
        }

        function iniciarPolling(consultaId) {
            // Limpiar polling anterior si existe
            if (pollingInterval) clearInterval(pollingInterval);

            pollingInterval = setInterval(async () => {
                try {
                    const response = await fetch(`/progreso/${consultaId}`);
                    const data = await response.json();

                    actualizarProgreso(data);

                    if (data.estado === 'completado') {
                        clearInterval(pollingInterval);
                        pollingInterval = null;
                        mostrarResultadoFinal(data);
                    } else if (data.estado === 'error') {
                        clearInterval(pollingInterval);
                        pollingInterval = null;
                        mostrarError(data.mensaje_error || 'Error durante el procesamiento.');
                    }
                } catch (err) {
                    // No detener polling por errores de red temporales
                    console.warn('Error polling:', err);
                }
            }, 3000);
        }

        function actualizarProgreso(data) {
            const progreso = data.progreso || 0;

            // Barra
            document.getElementById('barra-progreso').style.width = progreso + '%';
            document.getElementById('porcentaje-progreso').textContent = progreso + '%';

            // Texto
            if (data.procesadas > 0) {
                document.getElementById('texto-progreso').textContent = `Procesando cédula ${data.procesadas}/${data.total}`;
                document.getElementById('estado-actual').textContent = `Procesando... ${data.procesadas} de ${data.total} cédulas`;
            } else if (data.estado === 'procesando') {
                document.getElementById('texto-progreso').textContent = 'Conectando con ADRES...';
                document.getElementById('estado-actual').textContent = 'Iniciando scraper...';
            } else {
                document.getElementById('texto-progreso').textContent = 'En cola de procesamiento...';
                document.getElementById('estado-actual').textContent = 'Esperando turno en la cola...';
            }

            document.getElementById('contador-progreso').textContent = `${data.procesadas} / ${data.total} cédulas`;

            // Exitosas / fallidas
            document.getElementById('exitosas-fallidas').innerHTML =
                `<span class="text-green-600">${data.exitosas} <i class="fas fa-check"></i></span> ` +
                `<span class="text-red-500 ml-1">${data.fallidas} <i class="fas fa-times"></i></span>`;
        }

        // ─── PASO 4: Resultado final ───────────────────────────────────
        function mostrarResultadoFinal(data) {
            document.getElementById('res-total').textContent = data.total;
            document.getElementById('res-exitosas').textContent = data.exitosas;
            document.getElementById('res-fallidas').textContent = data.fallidas;
            document.getElementById('res-tiempo').textContent = data.fecha_generacion || '—';
            document.getElementById('btnDescargarExcel').href = `/descargar/${data.id}/resultado?formato=xlsx`;
            document.getElementById('btnDescargarCsv').href = `/descargar/${data.id}/resultado?formato=csv`;
            mostrarPaso('resultado');
        }

        // ─── Reprocesar ────────────────────────────────────────────────
        async function reprocesar(consultaId) {
            if (!confirm('¿Reprocesar esta consulta? Se reiniciarán los contadores.')) return;

            try {
                const response = await fetch(`/reprocesar/${consultaId}`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Content-Type': 'application/json',
                    }
                });

                const data = await response.json();

                if (!data.ok) {
                    alert(data.error || 'No se pudo reprocesar.');
                    return;
                }

                // Mostrar progreso y empezar polling
                consultaActualId = data.consulta_id;
                mostrarPaso('progreso');
                document.getElementById('estado-actual').textContent = 'Reprocesando...';
                document.getElementById('barra-progreso').style.width = '0%';
                document.getElementById('porcentaje-progreso').textContent = '0%';
                document.getElementById('contador-progreso').textContent = `0 / ${data.total}`;
                iniciarPolling(data.consulta_id);

            } catch (err) {
                alert('Error de conexión: ' + err.message);
            }
        }

        // ─── Error y reinicio ───────────────────────────────────────────
        function mostrarError(mensaje) {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
            document.getElementById('mensajeError').textContent = mensaje;
            mostrarPaso('error');
        }

        function reiniciar() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
            archivoRuta = null;
            archivoNombre = null;
            consultaActualId = null;
            document.getElementById('barra-progreso').style.width = '0%';
            document.getElementById('nombreArchivo').classList.add('hidden');
            document.getElementById('formValidar').reset();
            mostrarPaso('subir');
        }

        // ─── Auto-polling para consultas en proceso al cargar página ───
        document.addEventListener('DOMContentLoaded', () => {
            // Buscar filas con estado "procesando" y actualizar sus datos periódicamente
            const rows = document.querySelectorAll('tbody tr');
            const consultasProcesando = [];

            rows.forEach(row => {
                const estadoBadge = row.querySelector('.bg-yellow-100');
                if (estadoBadge) {
                    // Extraer el ID de la consulta del botón de reprocesar o del enlace de eliminar
                    const deleteForm = row.querySelector('form[action*="consultas/"]');
                    if (deleteForm) {
                        const action = deleteForm.getAttribute('action');
                        const match = action.match(/consultas\/(\d+)/);
                        if (match) consultasProcesando.push(match[1]);
                    }
                }
            });

            // Auto-refrescar la página si hay consultas procesando
            if (consultasProcesando.length > 0) {
                setInterval(() => {
                    location.reload();
                }, 15000); // Refrescar cada 15 segundos
            }
        });
    </script>
</body>
</html>
