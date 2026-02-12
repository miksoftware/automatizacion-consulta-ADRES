<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * Listar todos los usuarios.
     */
    public function index()
    {
        $usuarios = User::orderBy('name')->get();

        return view('usuarios.index', compact('usuarios'));
    }

    /**
     * Crear un nuevo usuario.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_admin' => $request->boolean('is_admin'),
        ]);

        return redirect()->route('usuarios.index')->with('success', 'Usuario creado exitosamente.');
    }

    /**
     * Actualizar un usuario existente.
     */
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
        ]);

        $user->name = $request->name;
        $user->email = $request->email;
        $user->is_admin = $request->boolean('is_admin');

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return redirect()->route('usuarios.index')->with('success', 'Usuario actualizado exitosamente.');
    }

    /**
     * Eliminar un usuario.
     */
    public function destroy(User $user)
    {
        // No permitir que el usuario se elimine a sí mismo
        if ($user->id === auth()->id()) {
            return redirect()->route('usuarios.index')->with('error', 'No puedes eliminar tu propia cuenta.');
        }

        $user->delete();

        return redirect()->route('usuarios.index')->with('success', 'Usuario eliminado exitosamente.');
    }
}
