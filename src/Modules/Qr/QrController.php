<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Qr;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use TurkiyeApi\Http\ApiException;
use TurkiyeApi\Http\Request;
use TurkiyeApi\Http\Response;

/**
 * QR codes as SVG or PNG. Returns the image itself, so the endpoint URL
 * can be used directly in <img src="...">.
 */
final class QrController
{
    private const MAX_DATA = 2000;

    public function image(Request $request): Response
    {
        $data = $request->required('data');
        if (strlen($data) > self::MAX_DATA) {
            throw ApiException::badRequest('`data` can be at most ' . self::MAX_DATA . ' bytes.', 'data');
        }
        $format = strtolower($request->input('format') ?? 'svg');
        if (!in_array($format, ['svg', 'png'], true)) {
            throw ApiException::badRequest('`format` must be svg or png.', 'format');
        }
        $size = $request->int('size', 300, 64, 2000);
        $margin = $request->int('margin', 4, 0, 10);
        $levels = ['L' => EccLevel::L, 'M' => EccLevel::M, 'Q' => EccLevel::Q, 'H' => EccLevel::H];
        $eccInput = strtoupper($request->input('ecc') ?? 'M');
        if (!isset($levels[$eccInput])) {
            throw ApiException::badRequest('`ecc` must be L, M, Q or H.', 'ecc');
        }
        $ecc = $levels[$eccInput];
        $fg = self::color($request->input('color') ?? '000000', 'color');
        $bg = self::color($request->input('background') ?? 'ffffff', 'background');
        $transparent = $request->bool('transparent', false);

        $base = [
            'eccLevel' => $ecc,
            'addQuietzone' => $margin > 0,
            'quietzoneSize' => $margin,
            'outputBase64' => false,
            'drawLightModules' => !$transparent || $format === 'png',
            'svgAddXmlHeader' => false,
            'cssClass' => 'qr',
        ];

        // The module count depends on the data, so build the matrix first and
        // pick the module scale that gets closest to the requested pixel size.
        try {
            $matrix = (new QRCode(new QROptions($base)))->addByteSegment($data)->getQRMatrix();
        } catch (\Throwable $e) {
            throw ApiException::unprocessable('The data does not fit into a QR code at this error-correction level.');
        }
        $base['scale'] = max(1, (int) round($size / $matrix->getSize()));

        if ($format === 'svg') {
            $options = new QROptions($base + [
                'outputType' => QROutputInterface::MARKUP_SVG,
                'moduleValues' => self::moduleValues('#' . $fg, $transparent ? 'none' : '#' . $bg),
            ]);
            $svg = (string) (new QRCode($options))->renderMatrix($matrix);
            // Give the SVG an intrinsic size so browsers render it at the requested size.
            $svg = (string) preg_replace('/<svg /', sprintf('<svg width="%1$d" height="%1$d" shape-rendering="crispEdges" ', $size), $svg, 1);
            return Response::raw($svg, 'image/svg+xml; charset=utf-8', 604800);
        }

        if (!extension_loaded('gd')) {
            throw new ApiException(503, 'png-unavailable', 'PNG output needs the GD extension on this server. Use format=svg.');
        }
        $options = new QROptions($base + [
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'moduleValues' => self::moduleValues(self::rgb($fg), self::rgb($bg)),
            'bgColor' => self::rgb($bg),
            'imageTransparent' => $transparent,
            'transparencyColor' => self::rgb($bg),
        ]);
        $png = (new QRCode($options))->renderMatrix($matrix);
        return Response::raw((string) $png, 'image/png', 604800);
    }

    /**
     * Maps every dark module type to $dark and every light type to $light.
     *
     * @param string|array{0: int, 1: int, 2: int} $dark
     * @param string|array{0: int, 1: int, 2: int} $light
     * @return array<int, mixed>
     */
    private static function moduleValues($dark, $light): array
    {
        $values = [];
        foreach ((new \ReflectionClass(QRMatrix::class))->getConstants() as $name => $value) {
            if (!is_int($value) || !str_starts_with($name, 'M_') || $value === QRMatrix::M_NULL) {
                continue;
            }
            $values[$value] = ($value & QRMatrix::IS_DARK) === QRMatrix::IS_DARK ? $dark : $light;
        }
        return $values;
    }

    private static function color(string $value, string $param): string
    {
        $hex = strtolower(ltrim(trim($value), '#'));
        if (preg_match('/^[0-9a-f]{3}$/', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/', $hex)) {
            throw ApiException::badRequest("`$param` must be a hex colour like 1a2b3c.", $param);
        }
        return $hex;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
