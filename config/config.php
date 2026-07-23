<?php

return [
    'name' => 'Albaranes',

    /*
    |--------------------------------------------------------------------------
    | Marca de "albarán"
    |--------------------------------------------------------------------------
    | Un albarán es un presupuesto (Quote) marcado con un campo personalizado.
    | Por defecto usamos custom_value4 = 'albaran'. Cámbialo si ese campo ya
    | lo usas para otra cosa. Debe ser uno de: custom_value1..custom_value4.
    */
    'marker_field' => env('ALBARAN_MARKER_FIELD', 'custom_value4'),
    'marker_value' => env('ALBARAN_MARKER_VALUE', 'albaran'),

    /*
    |--------------------------------------------------------------------------
    | Rótulo del documento
    |--------------------------------------------------------------------------
    | Palabra que se imprime como título ("Albarán") y que encabeza cada grupo
    | de líneas en la factura consolidada. NO se usa la traducción del core
    | 'delivery_note' porque en español es "Nota de Entrega", no "Albarán".
    */
    'document_label' => env('ALBARAN_DOCUMENT_LABEL', 'Albarán'),

    /*
    |--------------------------------------------------------------------------
    | Formato de la factura consolidada
    |--------------------------------------------------------------------------
    | header: una línea de cabecera por albarán ("Albarán Nº · fecha") y debajo
    |         sus líneas detalladas.
    */
    'invoice_layout' => env('ALBARAN_INVOICE_LAYOUT', 'header'),
];
