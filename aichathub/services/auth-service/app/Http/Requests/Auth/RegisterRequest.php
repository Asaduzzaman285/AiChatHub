<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed',
                           'regex:/[A-Z]/',   // at least one uppercase
                           'regex:/[0-9]/'],  // at least one digit
            'currency' => ['nullable', 'string', 'in:USD,BDT'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex' => 'Password must contain at least one uppercase letter and one number.',
        ];
    }
}
