<?php

namespace App\Http\Requests\Gestione;

use App\Models\Citta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvaCittaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $citta = $this->route('citta');

        return $citta instanceof Citta
            ? $this->user()->can('update', $citta)
            : $this->user()->can('create', Citta::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('citta', 'nome')->ignore($this->route('citta')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['nome' => trim((string) $this->input('nome'))]);
    }
}
