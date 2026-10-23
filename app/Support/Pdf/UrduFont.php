<?php

namespace App\Support\Pdf;

use Mpdf\Language\LanguageToFont;

/**
 * mPDF picks a font for each run of text by its language. Urdu (and unmarked Arabic-script text
 * such as a customer's Urdu name) is set in Lateef, a Naskh face shipped with mPDF that joins
 * Urdu letters correctly; Nastaliq is not available to mPDF (D-079).
 */
class UrduFont extends LanguageToFont
{
    public const FONT = 'lateef';

    /**
     * @param  string  $llcc  language tag, e.g. "ur" or "und-Arab"
     * @param  bool  $adobeCJK
     * @return array{0: bool, 1: string}
     */
    public function getLanguageOptions($llcc, $adobeCJK): array
    {
        $tags = explode('-', strtolower((string) $llcc));

        if (in_array($tags[0], ['ur', 'urd'], true) || ($tags[0] === 'und' && ($tags[1] ?? '') === 'arab')) {
            return [false, self::FONT];
        }

        return parent::getLanguageOptions($llcc, $adobeCJK);
    }
}
