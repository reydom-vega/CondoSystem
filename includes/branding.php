<?php
/** Display branding only. Business identifiers, records and routes stay independent. */
const CELANDINE_BRAND_NAME = 'The Celandine';
const CELANDINE_BRAND_WORDMARK = 'THE CELANDINE';

function brandWordmarkHtml(): string {
    return '<span class="celandine-wordmark brand-title"><span class="celandine-initial">T</span>HE <span class="celandine-initial">C</span>ELANDINE</span>';
}

function brandHtml(string $variant = 'full'): string {
    $variant = in_array($variant, ['full','compact','icon'], true) ? $variant : 'full';
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $dark = $e(buildUrl('celandine.svg')); $light = $e(buildUrl('celandineorange.svg'));
    return '<span class="celandine-brand celandine-brand--'.$variant.'" role="img" aria-label="The Celandine">'
        . '<img src="'.$dark.'" data-theme-logo data-logo-dark="'.$dark.'" data-logo-light="'.$light.'" width="44" height="46" class="brand-icon celandine-brand-emblem" alt="" aria-hidden="true">'
        . ($variant === 'icon' ? '' : brandWordmarkHtml()) . '</span>';
}

/** White paper always uses the original orange SVG, independent of screen theme. */
function brandDocumentHtml(int $height = 42): string {
    $height = max(24, min(60, $height));
    $image = 'data:image/svg+xml;base64,'.base64_encode((string)file_get_contents(dirname(__DIR__).'/celandineorange.svg'));
    return '<table class="celandine-document-brand" role="presentation"><tr><td class="celandine-document-emblem"><img src="'.$image.'" alt="" style="width:'.round($height*143/150,2).'px;height:'.$height.'px"></td><td style="font-size:'.round($height*.67,2).'px">'.brandWordmarkHtml().'</td></tr></table>';
}

function brandDocumentStyles(): string {
    static $css;
    if ($css === null) {
        $font = base64_encode((string)file_get_contents(dirname(__DIR__).'/assets/fonts/cinzel/Cinzel-Regular.ttf'));
        $css = '@font-face{font-family:CelandineRoman;src:url(data:font/ttf;base64,'.$font.') format("truetype");font-weight:400;font-style:normal}'
            . '.celandine-document-brand{width:auto;border-collapse:collapse;margin:0;color:#b45309}'
            . '.celandine-document-brand td{border:0;background:transparent;padding:0;vertical-align:middle;white-space:nowrap}'
            . '.celandine-document-brand .celandine-document-emblem{padding-right:10px}'
            . '.celandine-document-brand img{display:block;margin:0}'
            . '.celandine-wordmark{font-family:CelandineRoman,"DejaVu Serif",serif;font-weight:400;letter-spacing:.02em;line-height:1;white-space:nowrap}'
            . '.celandine-initial{font-size:1.24em}';
    }
    return $css;
}

/** PNG/CID gives mail clients the same font and emblem without SVG/font support. */
function brandEmailHtml(string $theme = 'dark'): string {
    $variant=$theme==='light'?'light':'dark';
    $cid=$variant==='light'?'celandine-brand-light':'celandine-brand';
    $dimensions = getimagesize(dirname(__DIR__).'/assets/branding/email-brand-'.$variant.'.png');
    $height = (int)round(300*$dimensions[1]/$dimensions[0]);
    return '<div style="margin-bottom:24px"><img src="cid:'.$cid.'" width="300" height="'.$height.'" alt="THE CELANDINE" style="display:block;max-width:100%;height:auto;border:0"></div>';
}

function brandMailFromName(string $configured): string {
    return preg_match('/\A(?:The\s+)?Celandine(?:\s+(?:Homes|Residences?|Recidences))?\z/i', trim($configured)) ? CELANDINE_BRAND_NAME : $configured;
}
