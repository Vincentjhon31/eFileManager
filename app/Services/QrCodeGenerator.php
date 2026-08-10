<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders QR codes as inline SVG.
 *
 * SVG rather than PNG for three reasons that all matter here:
 *
 *  - it needs no image extension, so nothing depends on GD or Imagick being
 *    compiled into whatever PHP the host is running this year;
 *  - it is sharp at any print resolution, and these are printed on an office
 *    laser printer and then photographed by a phone camera in a corridor;
 *  - it goes straight into the page, so a routing slip is one HTTP request with
 *    no image files to write, serve, clean up or accidentally expose.
 *
 * Error correction is set high on purpose. A routing slip gets folded, stapled,
 * rubber-stamped over and carried around a building for a week; a code that
 * only survives a pristine page is no use.
 */
class QrCodeGenerator
{
    /**
     * @param  int  $size  Rendered edge length in pixels (the SVG scales anyway).
     */
    public function svg(string $data, int $size = 180): string
    {
        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle($size, margin: 1),
                new SvgImageBackEnd,
            )
        );

        // Strip the XML prolog: this is embedded in an HTML page, where a
        // second <?xml declaration is invalid.
        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $writer->writeString(
            $data,
            Encoder::DEFAULT_BYTE_MODE_ECODING,
            ErrorCorrectionLevel::H(),
        )) ?? '';
    }

    /** The same thing as a data URI, for an <img> tag or a CSS background. */
    public function dataUri(string $data, int $size = 180): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($data, $size));
    }
}
