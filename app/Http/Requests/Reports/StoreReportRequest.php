<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'franchise_store' => ['nullable','string','max:20'],
            'from'            => ['required','date','before_or_equal:to'],
            'to'              => ['required','date','after_or_equal:from'],
            'without_bundle'  => ['sometimes'], // <--- NEW
        ];
    }

    public function inputStore(): string
    {
        return (string) $this->input('franchise_store');
    }

    public function inputFrom(): string
    {
        return (string) $this->input('from');
    }

    public function inputTo(): string
    {
        return (string) $this->input('to');
    }
}
