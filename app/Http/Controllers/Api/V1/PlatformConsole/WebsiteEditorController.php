<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteImage;
use App\Domain\Websites\Services\WebsiteEditor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The platform owner's page builder for any site: its draft, publishing,
 * discarding, and the site's own photos.
 *
 * Behind `platform-admin`, like every route of the platform console. The
 * site's address, booking rules and whether it is online stay on the
 * Websites screen ({@see WebsiteController}).
 */
class WebsiteEditorController extends Controller
{
    public function __construct(private readonly WebsiteEditor $editor) {}

    public function show(Request $request, Website $website): JsonResponse
    {
        return response()->json(['data' => $this->editor->state($website, $this->pageId($request))]);
    }

    /**
     * A fresh preview address, for one page of the draft: asked for when
     * the editor moves to another page, and when the last one expires.
     */
    public function preview(Request $request, Website $website): JsonResponse
    {
        return response()->json(['data' => ['url' => $this->editor->previewUrl($website, $this->pageId($request))]]);
    }

    public function saveDraft(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'array'],
            'version' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => $this->editor->saveDraft($website, $data['content'], (int) $data['version'])]);
    }

    public function publish(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);

        return response()->json(['data' => $this->editor->state($this->editor->publish($website, (int) $data['version']), $this->pageId($request))]);
    }

    public function discard(Request $request, Website $website): JsonResponse
    {
        return response()->json(['data' => $this->editor->state($this->editor->discard($website), $this->pageId($request))]);
    }

    public function storeImage(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'alt' => ['nullable', 'string', 'max:240'],
        ]);

        $image = $this->editor->storeImage($website, $data['file'], $data['alt'] ?? null);

        return response()->json(['data' => WebsiteEditor::image($website, $image)], 201);
    }

    public function destroyImage(Website $website, string $image): Response
    {
        $found = WebsiteImage::query()->where('website_id', $website->getKey())->findOrFail($image);

        $this->editor->deleteImage($website, $found);

        return response()->noContent();
    }

    private function pageId(Request $request): ?string
    {
        $page = $request->string('page')->toString();

        return preg_match('/^pg_[a-z0-9]{12}$/', $page) === 1 ? $page : null;
    }
}
