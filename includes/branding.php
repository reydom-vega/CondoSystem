<?php
/** Display branding only. Business identifiers, records and routes stay independent. */
const CELANDINE_BRAND_NAME = 'The Celandine';
const CELANDINE_BRAND_WORDMARK = 'THE CELANDINE';

/** Property identity only; never a substitute for a legal issuer or recipient address. */
function getPropertyInformation(): array {
    return [
        'name' => CELANDINE_BRAND_WORDMARK,
        'address' => '1115-A A. Bonifacio Ave., Brgy. Balintawak, Quezon City',
        'logo' => 'celandineorange.svg',
        'logo_dark' => 'celandine.svg',
        'email' => 'celandinehomes@gmail.com',
        'website' => 'thecelandinesystem.online',
    ];
}

function brandPropertyAddressHtml(bool $paper = false): string {
    $address = htmlspecialchars(getPropertyInformation()['address'], ENT_QUOTES, 'UTF-8');
    return '<div class="celandine-property-address"'.($paper ? ' style="font-family:DejaVu Sans,Arial,sans-serif;font-size:9px;line-height:1.5;color:#58657a;margin:6px 0 8px;white-space:normal;word-wrap:break-word"' : '').'>'.$address.'</div>';
}

function brandGeneratedAt(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s').' Asia/Manila';
}

function brandDocumentFooterHtml(): string {
    return '<div class="celandine-document-footer">'.htmlspecialchars(getPropertyInformation()['name'].' | Generated '.brandGeneratedAt(), ENT_QUOTES, 'UTF-8').'</div>';
}

/** Reserve the generator's existing bottom page margin; repeat the address on continuation pages. */
function brandPdfFooter(\Dompdf\Dompdf $pdf, string $context = '', ?string $generated = null): void {
    $property = getPropertyInformation();
    $generated = $generated ?? brandGeneratedAt();
    $pdf->getCanvas()->page_script(static function($page, $pages, $canvas, $metrics) use ($property, $context, $generated): void {
        $font = $metrics->getFont('DejaVu Sans', 'normal');
        $color = [.35, .4, .48]; $left = 28; $bottom = $canvas->get_height();
        $identity = $property['name'].($page > 1 ? ' | '.$property['address'] : ($context !== '' ? ' | '.$context : ''));
        $canvas->text($left, $bottom - 26, $identity, $font, 7, $color);
        $canvas->text($left, $bottom - 15, 'Generated '.$generated, $font, 7, $color);
        $number = 'Page '.$page.' of '.$pages;
        $canvas->text($canvas->get_width() - $left - $metrics->getTextWidth($number, $font, 7), $bottom - 15, $number, $font, 7, $color);
    });
}

/** Raster exports reuse the existing email-safe official logo and bundled fonts. */
function brandImageHeader(GdImage $image): void {
    $font = dirname(__DIR__).'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';
    $logo = imagecreatefrompng(dirname(__DIR__).'/assets/branding/email-brand-light.png');
    $height = (int)round(380 * imagesy($logo) / imagesx($logo));
    imagecopyresampled($image, $logo, 80, 40, 0, 0, 380, $height, imagesx($logo), imagesy($logo));
    imagedestroy($logo);
    imagettftext($image, 14, 0, 80, 116, imagecolorallocate($image, 86, 101, 122), $font, getPropertyInformation()['address']);
    imageline($image, 80, 137, imagesx($image) - 80, 137, imagecolorallocate($image, 180, 83, 9));
}

function brandImageFooter(GdImage $image, int $page, int $pages, string $context = 'Confidential', ?string $generated = null): void {
    $font = dirname(__DIR__).'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';
    $color = imagecolorallocate($image, 86, 101, 122); $bottom = imagesy($image);
    imagettftext($image, 12, 0, 80, $bottom - 54, $color, $font, getPropertyInformation()['name'].' | '.$context);
    imagettftext($image, 12, 0, 80, $bottom - 30, $color, $font, 'Generated '.($generated ?? brandGeneratedAt()));
    $number = 'Page '.$page.' / '.$pages; $bounds = imagettfbbox(12, 0, $font, $number);
    imagettftext($image, 12, 0, imagesx($image) - 80 - ($bounds[2] - $bounds[0]), $bottom - 30, $color, $font, $number);
}

function brandEmailFooterHtml(string $theme = 'dark'): string {
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $property = getPropertyInformation();
    return '<div data-property-footer style="border-top:1px solid '.($theme === 'light' ? '#e2e8f0' : '#374151').';padding-top:16px;margin-top:24px;color:'.($theme === 'light' ? '#64748b' : '#9ca3af').';font:11px/1.7 Arial,sans-serif"><strong>'.$e($property['name']).'</strong><br>'.$e($property['address']).'</div>';
}

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
    $image = 'data:image/svg+xml;base64,'.base64_encode((string)file_get_contents(dirname(__DIR__).'/'.getPropertyInformation()['logo']));
    return '<table class="celandine-document-brand" role="presentation"><tr><td class="celandine-document-emblem"><img src="'.$image.'" alt="" style="width:'.round($height*143/150,2).'px;height:'.$height.'px"></td><td style="font-size:'.round($height*.67,2).'px">'.brandWordmarkHtml().'</td></tr></table>'.brandPropertyAddressHtml(true);
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
        . '.celandine-initial{font-size:1.24em}'
        . '.celandine-document-footer{margin-top:20px;border-top:1px solid #dde3eb;padding-top:9px;font:9px/1.5 "DejaVu Sans",sans-serif;color:#58657a}';
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
