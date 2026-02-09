<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Consulta ADRES - Sistema de Consulta Masiva</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-lg shadow-lg p-6 mb-6 text-white">
            <h1 class="text-2xl font-bold flex items-center gap-3">
                <i class="fas fa-hospital-user"></i>
                Sistema de Consulta Masiva ADRES
            </h1>
            <p class="text-blue-100 mt-1">Consulta información de afiliación al sistema de salud colombiano</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Panel de Carga -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-lg p-6 sticky top-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-upload text-blue-600"></i>
                        Nueva Consulta
                    </h2>

                    <!-- Formulario -->
                    <div id="formulario">
                        <form id="formConsulta" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Archivo con cédulas
                                </label>
                                <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hover:border-blue-500 transition cursor-pointer" onclick="document.getElementById('archivo').click()">
                                    <i class="fas fa-file-excel text-4xl text-gray-400 mb-2"></i>
                                    <p class="text-sm text-gray-600">Click para seleccionar archivo</p>
                                    <p class="text-xs text-gray-400 mt-1">Excel o CSV (máx. 10MB)</p>
                                </div>
                                <input type="file" name="archivo" id="archivo" accept=".xlsx,.xls,.csv" required class="hidden" onchange="mostrarArchivo(this)">
                                <p id="nombreArchivo" class="mt-2 text-sm text-blue-600 hidden"></p>
                            </div>
                            <button type="submit" id="btnProcesar" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg transition flex items-center justify-center gap-2">
                                <i class="fas fa-search"></i>
                                Iniciar Consulta
                            </button>
                        </form>
                    </div>

                    <!-- Progreso -->
                    <div id="progreso" class="hidden">
                        <div class="text-center mb-4">
                            <div class="inline-block animate-spin rounded-full h-10 w-10 border-4 border-blue-600 border-t-transparent mb-3"></div>
                            <p class="text-gray-700 font-medium" id="textoProgreso">Procesando...</p>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3 mb-2">
                            <div id="barraProgreso" class="bg-blue-600 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
                        </div>
                        <p class="text-center text-sm text-gray-500" id="contadorProgreso">0 / 0 cédulas</p>
                    </div>

                    <!-- Resultado -->
                    <div id="resultado" class="hidden text-center">
                        <div class="mb-4">
                            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-check text-3xl text-green-600"></i>
                            </div>
                            <p class="text-lg font-medium text-gray-800">¡Consulta completada!</p>
                        </div>
                        <div class="space-y-2">
                            <a id="btnDescargarExcel" href="#" class="block w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition">
                                <i class="fas fa-file-excel mr-2"></i>Descargar Excel
                            </a>
                            <a id="btnDescargarCsv" href="#" class="block w-full bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition">
                                <i class="fas fa-file-csv mr-2"></i>Descargar CSV
                            </a>
                            <button onclick="reiniciar()" class="w-full text-blue-600 hover:text-blue-800 font-medium py-2">
                                <i class="fas fa-plus mr-1"></i>Nueva consulta
                            </button>
                        </div>
                    </div>

                    <!-- Error -->
                    <div id="error" class="hidden text-center">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-times text-3xl text-red-600"></i>
                        </div>
                        <p class="text-lg font-medium text-red-600 mb-4" id="mensajeError">Error</p>
                        <button onclick="reiniciar()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition">
                            Intentar de nuevo
                        </button>
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
        function mostrarArchivo(input) {
            const nombre = document.getElementById('nombreArchivo');
            if (input.files.length > 0) {
                nombre.textContent = input.files[0].name;
                nombre.classList.remove('hidden');
            }
        }

        const form = document.getElementById('formConsulta');
        const formularioDiv = document.getElementById('formulario');
        const progresoDiv = document.getElementById('progreso');
        const resultadoDiv = document.getElementById('resultado');
        const errorDiv = document.getElementById('error');
        const barraProgreso = document.getElementById('barraProgreso');
        const textoProgreso = document.getElementById('textoProgreso');
        const contadorProgreso = document.getElementById('contadorProgreso');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(form);
            
            formularioDiv.classList.add('hidden');
            progresoDiv.classList.remove('hidden');
            
            try {
                const response = await fetch('/procesar', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const reader = response.body.getReader();
                const decoder = new TextDecoder();

                while (true) {
                    const {value, done} = await reader.read();
                    if (done) break;
                    
                    const text = decoder.decode(value);
                    const lines = text.split('\n');
                    
                    for (const line of lines) {
                        if (line.startsWith('data: ')) {
                            const data = JSON.parse(line.substring(6));
                            
                            if (data.error) {
                                mostrarError(data.error);
                                return;
                            }
                            
                            if (data.progreso !== undefined) {
                                barraProgreso.style.width = data.progreso + '%';
                                contadorProgreso.textContent = `${data.procesadas} / ${data.total} cédulas`;
                                textoProgreso.textContent = `Consultando: ${data.cedula_actual}`;
                            }
                            
                            if (data.completado) {
                                mostrarResultado(data.consulta_id);
                            }
                        }
                    }
                }
            } catch (err) {
                mostrarError('Error de conexión: ' + err.message);
            }
        });

        function mostrarResultado(consultaId) {
            progresoDiv.classList.add('hidden');
            resultadoDiv.classList.remove('hidden');
            document.getElementById('btnDescargarExcel').href = `/descargar/${consultaId}/resultado?formato=xlsx`;
            document.getElementById('btnDescargarCsv').href = `/descargar/${consultaId}/resultado?formato=csv`;
        }

        function mostrarError(mensaje) {
            progresoDiv.classList.add('hidden');
            errorDiv.classList.remove('hidden');
            document.getElementById('mensajeError').textContent = mensaje;
        }

        function reiniciar() {
            formularioDiv.classList.remove('hidden');
            progresoDiv.classList.add('hidden');
            resultadoDiv.classList.add('hidden');
            errorDiv.classList.add('hidden');
            barraProgreso.style.width = '0%';
            document.getElementById('nombreArchivo').classList.add('hidden');
            form.reset();
        }
    </script>
</body>
</html>
