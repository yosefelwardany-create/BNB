<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * Turns a Blade template into a PDF.
 *
 * The configuration is the interesting part, and all of it is about not letting
 * a document template become an attack surface:
 *
 *  - **Remote fetching is off.** A template that could load a URL would make
 *    every statement render a request from our server to an address somebody
 *    else controls — the same server-side request forgery the webhook rule
 *    exists to prevent, arriving through a different door. It also means an
 *    `<img src="http://…">` silently renders nothing rather than hanging the
 *    queue on a slow host.
 *
 *  - **PHP in templates is off.** dompdf will evaluate `<?php ?>` inside HTML if
 *    allowed to. Nothing here needs it, and a document containing guest-supplied
 *    text is exactly where that would go wrong.
 *
 *  - **The chroot is the views directory.** The only local files a template may
 *    reference are ones we shipped.
 *
 * Documents are A4 portrait because every owner and tax authority this product
 * serves expects that, and a statement that prints across two pages because it
 * was laid out for Letter is a statement somebody complains about.
 */
class PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data, string $paperSize = 'A4'): string
    {
        $html = View::make($template, $data)->render();

        return $this->fromHtml($html, $paperSize);
    }

    public function fromHtml(string $html, string $paperSize = 'A4'): string
    {
        $dompdf = new Dompdf($this->options());

        $dompdf->setPaper($paperSize, 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $output = $dompdf->output();

        // An empty render is a failure that would otherwise be stored as a
        // zero-byte document and discovered by the owner who opened it.
        if ($output === null || $output === '') {
            throw new \RuntimeException('The document renderer produced nothing.');
        }

        return $output;
    }

    private function options(): Options
    {
        $options = new Options;

        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setChroot([resource_path('views')]);

        // Inside the writable allowance; the framework's storage path is the one
        // directory guaranteed to exist and be writable in every environment,
        // including a read-only container image.
        $options->setTempDir(storage_path('app/dompdf'));
        $options->setFontCache(storage_path('app/dompdf/fonts'));

        foreach ([$options->getTempDir(), $options->getFontCache()] as $directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
        }

        // The standard 14 fonts need no embedding, which keeps a statement under
        // 50KB instead of over a megabyte and means no font file has to ship.
        $options->setDefaultFont('Helvetica');

        return $options;
    }
}
