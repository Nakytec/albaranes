<?php

namespace Modules\Albaranes\Services;

use App\Models\Client;
use App\Models\Quote;
use Illuminate\Support\Collection;

/**
 * Utilidades sobre "albaranes", que son presupuestos (Quote) marcados con un
 * campo personalizado configurable. Conviven con presupuestos normales.
 */
class AlbaranService
{
    public function markerField(): string
    {
        $field = (string) config('albaranes.marker_field');

        return in_array($field, ['custom_value1', 'custom_value2', 'custom_value3', 'custom_value4'], true)
            ? $field
            : 'custom_value4';
    }

    public function markerValue(): string
    {
        return (string) config('albaranes.marker_value');
    }

    /** ¿Este presupuesto está marcado como albarán? */
    public function isAlbaran(Quote $quote): bool
    {
        return (string) $quote->{$this->markerField()} === $this->markerValue();
    }

    /** Marca/desmarca un presupuesto como albarán (sin guardar). */
    public function setAlbaran(Quote $quote, bool $on): Quote
    {
        $quote->{$this->markerField()} = $on ? $this->markerValue() : null;

        return $quote;
    }

    /**
     * Albaranes de un cliente pendientes de facturar (no convertidos).
     *
     * @return Collection<int, Quote>
     */
    public function pendingForClient(Client $client): Collection
    {
        return Quote::query()
            ->where('company_id', $client->company_id)
            ->where('client_id', $client->id)
            ->where($this->markerField(), $this->markerValue())
            ->where('status_id', '!=', Quote::STATUS_CONVERTED)
            ->whereNull('deleted_at')
            ->where('is_deleted', false)
            ->orderBy('date')
            ->orderBy('number')
            ->get();
    }

    /**
     * Presupuestos del cliente que AÚN no son albaranes (candidatos a marcar).
     * No convertidos, no borrados.
     *
     * @return Collection<int, Quote>
     */
    public function candidatesForClient(Client $client, int $limit = 50): Collection
    {
        return Quote::query()
            ->where('company_id', $client->company_id)
            ->where('client_id', $client->id)
            ->where(function ($q) {
                $q->whereNull($this->markerField())
                  ->orWhere($this->markerField(), '!=', $this->markerValue());
            })
            ->where('status_id', '!=', Quote::STATUS_CONVERTED)
            ->whereNull('deleted_at')
            ->where('is_deleted', false)
            ->orderByDesc('date')
            ->orderByDesc('number')
            ->limit($limit)
            ->get();
    }
}
