<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Gestión de Usuarios - Sistema ADRES</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .fade-in { animation: fadeIn 0.3s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .modal-backdrop { background: rgba(0,0,0,0.5); }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-5xl">

        <!-- Header con menú -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-lg shadow-lg p-6 mb-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-3">
                        <i class="fas fa-hospital-user"></i>
                        Sistema de Consulta Masiva del Sistema de Seguridad Social
                    </h1>
                    <p class="text-blue-100 mt-1">Consulta información de afiliación al sistema de salud colombiano</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-blue-200 text-sm"><i class="fas fa-user mr-1"></i>{{ Auth::user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="text-blue-200 hover:text-white text-sm transition" title="Cerrar sesión">
                            <i class="fas fa-sign-out-alt"></i>
                        </button>
                    </form>
                </div>
            </div>
            <!-- Menú de navegación -->
            <div class="flex gap-4 mt-4 border-t border-blue-500 pt-3">
                <a href="{{ route('consultas.index') }}" class="text-blue-200 hover:text-white transition flex items-center gap-2 text-sm font-medium px-3 py-1 rounded hover:bg-blue-700">
                    <i class="fas fa-upload"></i> Consulta Masiva
                </a>
                <a href="{{ route('consultas.consultar') }}" class="text-blue-200 hover:text-white transition flex items-center gap-2 text-sm font-medium px-3 py-1 rounded hover:bg-blue-700">
                    <i class="fas fa-search"></i> Consultar Cédula
                </a>
                <a href="{{ route('usuarios.index') }}" class="text-white bg-blue-700 transition flex items-center gap-2 text-sm font-medium px-3 py-1 rounded">
                    <i class="fas fa-users-cog"></i> Usuarios
                </a>
            </div>
        </div>

        <!-- Mensajes de éxito/error -->
        @if (session('success'))
            <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-4 flex items-center gap-2 text-green-700 text-sm fade-in">
                <i class="fas fa-check-circle"></i>
                <span>{{ session('success') }}</span>
            </div>
        @endif
        @if (session('error'))
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4 flex items-center gap-2 text-red-700 text-sm fade-in">
                <i class="fas fa-exclamation-circle"></i>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Panel: Crear Usuario -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-lg p-6 sticky top-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-user-plus text-blue-600"></i>
                        Nuevo Usuario
                    </h2>

                    <form method="POST" action="{{ route('usuarios.store') }}">
                        @csrf

                        <div class="mb-4">
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" required
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="Nombre completo">
                            @error('name')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Correo Electrónico</label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" required
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="correo@ejemplo.com">
                            @error('email')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label>
                            <input type="password" id="password" name="password" required
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="Mínimo 6 caracteres">
                            @error('password')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mb-5">
                            <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirmar Contraseña</label>
                            <input type="password" id="password_confirmation" name="password_confirmation" required
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="Repita la contraseña">
                        </div>

                        <div class="mb-5">
                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                                <input type="checkbox" name="is_admin" value="1" {{ old('is_admin') ? 'checked' : '' }}
                                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="font-medium">Administrador</span>
                                <span class="text-gray-400 text-xs">(acceso total al sistema)</span>
                            </label>
                        </div>

                        <button type="submit"
                                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 rounded-lg transition flex items-center justify-center gap-2 text-sm">
                            <i class="fas fa-user-plus"></i>
                            Crear Usuario
                        </button>
                    </form>
                </div>
            </div>

            <!-- Panel: Lista de Usuarios -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-users text-blue-600"></i>
                            Usuarios Registrados
                        </h2>
                        <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded-full">
                            {{ $usuarios->count() }} usuario(s)
                        </span>
                    </div>

                    @if ($usuarios->isEmpty())
                        <div class="text-center py-10 text-gray-400">
                            <i class="fas fa-users text-4xl mb-3"></i>
                            <p>No hay usuarios registrados.</p>
                        </div>
                    @else
                        <div class="space-y-3">
                            @foreach ($usuarios as $usuario)
                                <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition fade-in">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                                                <i class="fas fa-user text-blue-600"></i>
                                            </div>
                                            <div>
                                                <h3 class="font-semibold text-gray-800 text-sm">{{ $usuario->name }}</h3>
                                                <p class="text-gray-500 text-xs">{{ $usuario->email }}</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            @if ($usuario->id === auth()->id())
                                                <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">Tú</span>
                                            @endif
                                            @if ($usuario->is_admin)
                                                <span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full"><i class="fas fa-shield-alt mr-1"></i>Admin</span>
                                            @else
                                                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">Usuario</span>
                                            @endif
                                            <span class="text-xs text-gray-400">
                                                Creado: {{ $usuario->created_at ? $usuario->created_at->format('d/m/Y') : 'N/A' }}
                                            </span>
                                            <button onclick="openEditModal({{ $usuario->id }}, '{{ addslashes($usuario->name) }}', '{{ addslashes($usuario->email) }}', {{ $usuario->is_admin ? 'true' : 'false' }})"
                                                    class="text-blue-600 hover:text-blue-800 text-sm p-1 transition" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            @if ($usuario->id !== auth()->id())
                                                <button onclick="confirmDelete({{ $usuario->id }}, '{{ addslashes($usuario->name) }}')"
                                                        class="text-red-500 hover:text-red-700 text-sm p-1 transition" title="Eliminar">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Editar Usuario -->
    <div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center modal-backdrop">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6 fade-in">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-user-edit text-blue-600"></i>
                    Editar Usuario
                </h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 transition">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <form id="editForm" method="POST">
                @csrf
                @method('PUT')

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                    <input type="text" id="editName" name="name" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Correo Electrónico</label>
                    <input type="email" id="editEmail" name="email" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nueva Contraseña <span class="text-gray-400">(dejar vacío para no cambiar)</span></label>
                    <input type="password" id="editPassword" name="password"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="Nueva contraseña (opcional)">
                </div>

                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar Nueva Contraseña</label>
                    <input type="password" id="editPasswordConfirm" name="password_confirmation"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="Confirmar contraseña">
                </div>

                <div class="mb-5">
                    <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                        <input type="checkbox" name="is_admin" id="editIsAdmin" value="1"
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="font-medium">Administrador</span>
                        <span class="text-gray-400 text-xs">(acceso total al sistema)</span>
                    </label>
                </div>

                <div class="flex gap-3">
                    <button type="button" onclick="closeEditModal()"
                            class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium py-2 rounded-lg transition text-sm">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 rounded-lg transition flex items-center justify-center gap-2 text-sm">
                        <i class="fas fa-save"></i> Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Confirmar Eliminación -->
    <div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center modal-backdrop">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 fade-in">
            <div class="text-center mb-4">
                <div class="inline-flex items-center justify-center w-14 h-14 bg-red-100 rounded-full mb-3">
                    <i class="fas fa-exclamation-triangle text-2xl text-red-600"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">¿Eliminar usuario?</h3>
                <p class="text-gray-500 text-sm mt-1">Esta acción eliminará permanentemente al usuario <strong id="deleteUserName"></strong>.</p>
            </div>

            <form id="deleteForm" method="POST">
                @csrf
                @method('DELETE')

                <div class="flex gap-3">
                    <button type="button" onclick="closeDeleteModal()"
                            class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium py-2 rounded-lg transition text-sm">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white font-medium py-2 rounded-lg transition flex items-center justify-center gap-2 text-sm">
                        <i class="fas fa-trash-alt"></i> Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ─── Modal Editar ───
        function openEditModal(id, name, email, isAdmin) {
            document.getElementById('editForm').action = `/usuarios/${id}`;
            document.getElementById('editName').value = name;
            document.getElementById('editEmail').value = email;
            document.getElementById('editPassword').value = '';
            document.getElementById('editPasswordConfirm').value = '';
            document.getElementById('editIsAdmin').checked = isAdmin;

            const modal = document.getElementById('editModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeEditModal() {
            const modal = document.getElementById('editModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // ─── Modal Eliminar ───
        function confirmDelete(id, name) {
            document.getElementById('deleteForm').action = `/usuarios/${id}`;
            document.getElementById('deleteUserName').textContent = name;

            const modal = document.getElementById('deleteModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeDeleteModal() {
            const modal = document.getElementById('deleteModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Cerrar modales al hacer clic fuera
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });

        // Cerrar con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
                closeDeleteModal();
            }
        });

        // Ocultar mensajes de éxito/error después de 5 segundos
        setTimeout(() => {
            document.querySelectorAll('.bg-green-50, .bg-red-50').forEach(el => {
                el.style.transition = 'opacity 0.5s';
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>
