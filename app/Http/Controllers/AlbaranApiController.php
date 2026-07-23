<?php

namespace Modules\Albaranes\Http\Controllers;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Quote;
use App\Models\QuoteInvitation;
use App\Services\Quote\GetQuotePdf;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Albaranes\Services\AlbaranConsolidator;
use Modules\Albaranes\Services\AlbaranLabels;
use Modules\Albaranes\Services\AlbaranMailer;
use Modules\Albaranes\Services\AlbaranService;
use Symfony\Component\HttpFoundation\Response;

class AlbaranApiController extends Controller
{
    use MakesHash;

    public function __construct(private AlbaranService $albaranes = new AlbaranService())
    {
    }

    /** Albaranes pendientes de facturar de un cliente, con su estado de envío. */
    public function index(Request $request, Client $client): JsonResponse
    {
        $this->authorizeCompany($client->company_id);

        $data = $this->albaranes->pendingForClient($client)->map(function (Quote $q) {
            $enviadas = $q->invitations->filter(fn (QuoteInvitation $i) => $i->sent_date);

            return [
                'id' => $q->hashed_id,
                'number' => $q->number,
                'date' => $q->date,
                'amount' => (float) $q->amount,
                'po_number' => $q->po_number,
                'sent_at' => $enviadas->max('sent_date'),
                'sent_to' => $enviadas->map(fn (QuoteInvitation $i) => optional($i->contact)->email)->filter()->unique()->values(),
            ];
        })->values();

        return response()->json([
            'client' => ['id' => $client->hashed_id, 'name' => $client->present()->name()],
            'contacts' => $this->contactsOf($client),
            'albaranes' => $data,
        ]);
    }

    /**
     * Contactos del cliente a los que se puede enviar un albarán.
     *
     * @return array<int, array<string, mixed>>
     */
    private function contactsOf(Client $client): array
    {
        return $client->contacts
            ->filter(fn (ClientContact $c) => $c->email)
            ->map(fn (ClientContact $c) => [
                'id' => $c->hashed_id,
                'name' => trim($c->first_name . ' ' . $c->last_name) ?: $c->email,
                'email' => $c->email,
                'default' => (bool) $c->send_email,
            ])->values()->all();
    }

    /** Presupuestos del cliente que aún no son albaranes (para marcarlos). */
    public function candidates(Request $request, Client $client): JsonResponse
    {
        $this->authorizeCompany($client->company_id);

        $data = $this->albaranes->candidatesForClient($client)->map(fn (Quote $q) => [
            'id' => $q->hashed_id,
            'number' => $q->number,
            'date' => $q->date,
            'amount' => (float) $q->amount,
            'po_number' => $q->po_number,
        ])->values();

        return response()->json(['presupuestos' => $data]);
    }

    /** Consolida los albaranes seleccionados en una factura. */
    public function consolidate(Request $request, Client $client): JsonResponse
    {
        $this->authorizeCompany($client->company_id);

        $validated = $request->validate([
            'albaranes' => ['required', 'array', 'min:1'],
            'albaranes.*' => ['string'],
        ]);

        $ids = collect($validated['albaranes'])->map(fn ($id) => $this->decodePrimaryKey($id))->all();

        $quotes = Quote::query()
            ->where('company_id', $client->company_id)
            ->where('client_id', $client->id)
            ->whereIn('id', $ids)
            ->where($this->albaranes->markerField(), $this->albaranes->markerValue())
            ->where('status_id', '!=', Quote::STATUS_CONVERTED)
            ->whereNull('deleted_at')
            ->get();

        if ($quotes->count() !== count($ids)) {
            return response()->json([
                'message' => 'Algún albarán no existe, no pertenece al cliente, no está marcado como albarán o ya está facturado.',
            ], 422);
        }

        $invoice = (new AlbaranConsolidator($this->albaranes))->consolidate($client, $quotes);

        return response()->json([
            'message' => 'Factura creada a partir de ' . $quotes->count() . ' albarán(es).',
            'invoice' => [
                'id' => $invoice->hashed_id,
                'number' => $invoice->number,
                'amount' => (float) $invoice->amount,
            ],
        ], 201);
    }

    /** Marca o desmarca un presupuesto como albarán. */
    public function toggle(Request $request, Quote $quote): JsonResponse
    {
        $this->authorizeCompany($quote->company_id);

        $on = $request->boolean('albaran', true);
        $this->albaranes->setAlbaran($quote, $on)->save();

        return response()->json([
            'id' => $quote->hashed_id,
            'is_albaran' => $this->albaranes->isAlbaran($quote->fresh()),
        ]);
    }

    /** PDF del albarán: el del presupuesto, pero rotulado "Albarán". */
    public function pdf(Request $request, Quote $quote): Response
    {
        $this->authorizeCompany($quote->company_id);

        if ($quote->invitations()->count() === 0) {
            $quote->service()->createInvitations()->save();
            $quote = $quote->fresh();
        }

        $pdf = (new AlbaranLabels())->apply($quote, fn () => (new GetQuotePdf($quote->fresh()))->run());

        $filename = $this->slug(config('albaranes.document_label') . '-' . ($quote->number ?: $quote->id)) . '.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    /**
     * Envía el albarán por correo al cliente. El PDF adjunto, el asunto y el
     * cuerpo salen rotulados "Albarán", no "Presupuesto".
     *
     * Body opcional: {"contacts": ["ID1","ID2"]} para elegir destinatarios; si
     * no se indica, se envía a los contactos que reciben correo por defecto.
     */
    public function email(Request $request, Quote $quote): JsonResponse
    {
        $this->authorizeCompany($quote->company_id);

        abort_unless($this->albaranes->isAlbaran($quote), 422, 'Este presupuesto no está marcado como albarán.');

        $validated = $request->validate([
            'contacts' => ['sometimes', 'array'],
            'contacts.*' => ['string'],
        ]);

        try {
            $sent = (new AlbaranMailer())->send($quote, $validated['contacts'] ?? []);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Albarán enviado a ' . implode(', ', $sent) . '.',
            'sent_to' => $sent,
            'sent_at' => $quote->fresh()->last_sent_date,
        ]);
    }

    private function slug(string $text): string
    {
        return preg_replace('/[^A-Za-z0-9\-_]/', '_', $text);
    }

    private function authorizeCompany(int $company_id): void
    {
        abort_unless($company_id === auth()->user()->company()->id, 404, 'Not found');
    }
}
