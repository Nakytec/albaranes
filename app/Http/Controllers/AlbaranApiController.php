<?php

namespace Modules\Albaranes\Http\Controllers;

use App\Models\Client;
use App\Models\Quote;
use App\Services\Quote\GetQuotePdf;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Lang;
use Modules\Albaranes\Services\AlbaranConsolidator;
use Modules\Albaranes\Services\AlbaranService;
use Symfony\Component\HttpFoundation\Response;

class AlbaranApiController extends Controller
{
    use MakesHash;

    public function __construct(private AlbaranService $albaranes = new AlbaranService())
    {
    }

    /** Albaranes pendientes de facturar de un cliente. */
    public function index(Request $request, Client $client): JsonResponse
    {
        $this->authorizeCompany($client->company_id);

        $data = $this->albaranes->pendingForClient($client)->map(fn (Quote $q) => [
            'id' => $q->hashed_id,
            'number' => $q->number,
            'date' => $q->date,
            'amount' => (float) $q->amount,
            'po_number' => $q->po_number,
        ])->values();

        return response()->json([
            'client' => ['id' => $client->hashed_id, 'name' => $client->present()->name()],
            'albaranes' => $data,
        ]);
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

    /**
     * PDF del albarán: el mismo PDF del presupuesto pero con el título "Albarán".
     * IN construye ese rótulo desde las traducciones personalizadas del cliente
     * (settings->translations, vía Ninja::transformTranslations) y recarga el
     * cliente desde BD al renderizar, así que inyectamos la traducción en los
     * settings del cliente durante el render y la restauramos siempre (finally).
     */
    public function pdf(Request $request, Quote $quote): Response
    {
        $this->authorizeCompany($quote->company_id);

        if ($quote->invitations()->count() === 0) {
            $quote->service()->createInvitations()->save();
            $quote = $quote->fresh();
        }

        $client = $quote->client;
        $locale = optional($quote->invitations->first()?->contact)->preferredLocale() ?: $client->locale();
        $settings = $client->settings;
        $original = $settings->translations ?? null;

        try {
            $settings->translations = (object) array_merge(
                (array) ($original ?: []),
                $this->albaranTranslations($locale)
            );
            $client->settings = $settings;
            $client->saveQuietly();

            $pdf = (new GetQuotePdf($quote->fresh()))->run();
        } finally {
            $settings = $client->settings;
            if ($original === null) {
                unset($settings->translations);
            } else {
                $settings->translations = $original;
            }
            $client->settings = $settings;
            $client->saveQuietly();
        }

        $filename = $this->slug(config('albaranes.document_label') . '-' . ($quote->number ?: $quote->id)) . '.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    /**
     * Construye las traducciones que convierten "presupuesto" en "Albarán" en
     * todas las etiquetas del PDF (título, "emitido a", "fecha de", "términos
     * de"…) para el idioma dado. Se aplican sólo durante el render del albarán.
     *
     * @return array<string, string>
     */
    private function albaranTranslations(string $locale): array
    {
        $label = (string) config('albaranes.document_label');
        $word = (string) Lang::get('texts.quote', [], $locale);

        if ($word === '' || $word === 'texts.quote') {
            return [];
        }

        $overrides = [];
        foreach ((array) Lang::get('texts', [], $locale) as $key => $value) {
            if (is_string($value) && mb_stripos($value, $word) !== false) {
                $overrides[$key] = str_ireplace($word, $label, $value);
            }
        }

        return $overrides;
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
