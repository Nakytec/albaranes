<?php

namespace Modules\Albaranes\Services;

use App\Models\Quote;
use Closure;
use Illuminate\Support\Facades\Lang;

/**
 * Invoice Ninja construye los rótulos de un documento (título del PDF, asunto y
 * cuerpo del correo) a partir de las traducciones personalizadas del cliente
 * (settings->translations, vía Ninja::transformTranslations), y recarga el
 * cliente desde BD al renderizar. Para que un albarán salga como "Albarán" y no
 * como "Presupuesto", inyectamos esas traducciones en el cliente mientras dura
 * la operación y las restauramos siempre al terminar (finally).
 */
class AlbaranLabels
{
    /**
     * Ejecuta $callback con los rótulos de albarán activos para este cliente.
     * Todo lo que se genere dentro (PDF, asunto y cuerpo del email) dirá
     * "Albarán"; fuera del bloque, el cliente queda exactamente como estaba.
     */
    public function apply(Quote $quote, Closure $callback): mixed
    {
        $client = $quote->client;
        $locale = optional($quote->invitations->first()?->contact)->preferredLocale() ?: $client->locale();
        $settings = $client->settings;
        $original = $settings->translations ?? null;

        try {
            $settings->translations = (object) array_merge(
                (array) ($original ?: []),
                $this->translations($locale)
            );
            $client->settings = $settings;
            $client->saveQuietly();

            return $callback();
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
    }

    /**
     * Traducciones que convierten "presupuesto" en "Albarán" en todas las
     * etiquetas del idioma dado (título, "emitido a", "fecha de", asunto y
     * cuerpo del correo…).
     *
     * @return array<string, string>
     */
    public function translations(string $locale): array
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
}
