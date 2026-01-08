<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class StoreItemsMatrixRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],

            // store can be: omitted/null, "all", "03795-00016", or array of store codes
            'store'   => ['nullable'],
            'stores'  => ['nullable', 'array'],
            'stores.*' => ['string'],

            // items can be: omitted/null, or array of ints
            'items'   => ['nullable', 'array'],
            'items.*' => ['integer'],

            'without_bundle' => ['sometimes', 'boolean'],
        ];
    }

    public function from(): string
    {
        return $this->input('from');
    }
    public function to(): string
    {
        return $this->input('to');
    }

    public function withoutBundle(): bool
    {
        return $this->boolean('without_bundle');
    }

    /**
     * Normalize store filters:
     * - if stores[] given => use it
     * - else if store given:
     *    - "all" => null (no filter)
     *    - string => [string]
     * - else null
     */
    public function storeFilter(): ?array
    {
        $stores = $this->input('stores');
        if (is_array($stores) && count($stores)) {
            return array_values(array_filter($stores, fn($v) => is_string($v) && $v !== ''));
        }

        $store = $this->input('store');
        if ($store === null || $store === '') return null;

        if (is_string($store) && strtolower($store) === 'all') return null;

        return is_string($store) ? [$store] : null;
    }

    public function itemFilter(): ?array
    {
        $items = $this->input('items');
        if (!is_array($items) || !count($items)) return null;

        // unique + ints
        $items = array_values(array_unique(array_map('intval', $items)));
        return $items;
    }
}
