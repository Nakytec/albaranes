<?php

namespace Modules\Albaranes\Services;

use App\Factory\CloneQuoteToInvoiceFactory;
use App\Factory\InvoiceInvitationFactory;
use App\Factory\InvoiceItemFactory;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Repositories\InvoiceRepository;
use App\Utils\Traits\MakesHash;
use Illuminate\Support\Collection;

/**
 * Agrupa varios albaranes (presupuestos marcados) de un cliente en UNA factura,
 * volcando el detalle línea por línea de cada albarán con una cabecera por cada
 * uno. Reutiliza la maquinaria de conversión de presupuesto→factura del core.
 */
class AlbaranConsolidator
{
    use MakesHash;

    public function __construct(private AlbaranService $albaranes = new AlbaranService())
    {
    }

    /**
     * @param  Collection<int, Quote>  $quotes  Albaranes a consolidar (ya validados: mismo cliente, marcados, no convertidos).
     */
    public function consolidate(Client $client, Collection $quotes): Invoice
    {
        if ($quotes->isEmpty()) {
            throw new \RuntimeException('No se han indicado albaranes para facturar.');
        }

        /** @var Quote $first */
        $first = $quotes->first();

        // Idioma del cliente para que los rótulos (fecha, etc.) salgan en su lengua.
        app()->setLocale($client->locale());

        $invoice = CloneQuoteToInvoiceFactory::create($first, $first->user_id);
        $invoice->design_id = $this->decodePrimaryKey($client->getSetting('invoice_design_id'));
        $invoice->line_items = $this->buildLineItems($quotes);

        // Invitaciones: sólo los contactos que ya tenían invitación en los albaranes.
        $invites = $this->buildInvitations($invoice, $quotes);

        $invoice_array = $invoice->toArray();
        $invoice_array['invitations'] = $invites;
        $invoice_array['line_items'] = $invoice->line_items;

        $invoice = (new InvoiceRepository())->save($invoice_array, $invoice);

        $invoice = $invoice->service()
            ->fillDefaults()
            ->applyNumber()
            ->adjustInventory()
            ->save();

        // Enlaza cada albarán a la factura y lo marca como convertido.
        foreach ($quotes as $quote) {
            $quote->invoice_id = $invoice->id;
            $quote->status_id = Quote::STATUS_CONVERTED;
            $quote->saveQuietly();
        }

        return $invoice->fresh();
    }

    /**
     * Previsualiza las líneas que tendría la factura consolidada (sin crearla).
     * Útil para tests y para una futura vista previa.
     *
     * @param  Collection<int, Quote>  $quotes
     * @return array<int, \stdClass>
     */
    public function previewLineItems(Collection $quotes): array
    {
        return $this->buildLineItems($quotes);
    }

    /**
     * Construye las líneas: por cada albarán, una línea de cabecera seguida de
     * sus líneas detalladas.
     *
     * @param  Collection<int, Quote>  $quotes
     * @return array<int, \stdClass>
     */
    private function buildLineItems(Collection $quotes): array
    {
        $items = [];
        $sort = 0;

        foreach ($quotes as $quote) {
            $header = InvoiceItemFactory::create();
            $header->type_id = '1';
            $header->quantity = 0;
            $header->cost = 0;
            $header->line_total = 0;
            $header->product_key = trim(config('albaranes.document_label') . ' ' . $quote->number);
            $header->notes = $this->headerNotes($quote);
            $header->sort_id = (string) $sort++;
            $header->custom_value1 = (string) $quote->number;
            $items[] = $header;

            foreach ($this->lineItemsOf($quote) as $line) {
                $line = (object) (array) $line;
                $line->sort_id = (string) $sort++;
                // Trazabilidad: deja el nº de albarán en un custom del ítem si está libre.
                if (empty($line->custom_value4 ?? '')) {
                    $line->custom_value4 = (string) $quote->number;
                }
                $items[] = $line;
            }
        }

        return $items;
    }

    private function headerNotes(Quote $quote): string
    {
        $format = optional($quote->company)->date_format() ?: 'Y-m-d';
        $date = $quote->date ? \Carbon\Carbon::parse($quote->date)->format($format) : '';

        return $date ? (ctrans('texts.date') . ': ' . $date) : '';
    }

    /** @return array<int, mixed> */
    private function lineItemsOf(Quote $quote): array
    {
        $items = $quote->line_items;

        if (is_string($items)) {
            $items = json_decode($items);
        }

        return is_array($items) ? $items : (array) $items;
    }

    /**
     * @param  Collection<int, Quote>  $quotes
     * @return array<int, \App\Models\InvoiceInvitation>
     */
    private function buildInvitations(Invoice $invoice, Collection $quotes): array
    {
        $seen = [];
        $invites = [];

        foreach ($quotes as $quote) {
            foreach ($quote->invitations as $inv) {
                if (isset($seen[$inv->client_contact_id])) {
                    continue;
                }
                $seen[$inv->client_contact_id] = true;

                $ii = InvoiceInvitationFactory::create($invoice->company_id, $invoice->user_id);
                $ii->key = $this->createDbHash($invoice->company->db);
                $ii->client_contact_id = $inv->client_contact_id;
                $invites[] = $ii;
            }
        }

        return $invites;
    }
}
