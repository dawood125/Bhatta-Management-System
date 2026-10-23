<?php

namespace App\Support\Pdf;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Turns a Blade view into a PDF with mPDF (D-014: dompdf cannot join Urdu letters). Pages are
 * right-to-left when the current language is Urdu; Urdu words inside English pages (names,
 * addresses) are still shaped correctly.
 */
class PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data, string $title, string $author): string
    {
        $urdu = app()->getLocale() === 'ur';

        $tempDir = storage_path('framework/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 16,
            'tempDir' => $tempDir,
            'default_font' => $urdu ? UrduFont::FONT : 'dejavusans',
            'directionality' => $urdu ? 'rtl' : 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'languageToFont' => new UrduFont,
        ]);

        $mpdf->SetTitle($title);
        $mpdf->SetAuthor($author);
        $mpdf->SetCreator((string) config('app.name'));
        $mpdf->WriteHTML(view($view, $data)->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
