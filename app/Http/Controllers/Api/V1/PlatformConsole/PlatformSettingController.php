<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Platform\Services\PlatformSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform configuration.
 *
 * The write accepts only keys the service declares, so a console cannot invent a
 * setting that nothing reads, and nothing here holds a secret.
 */
class PlatformSettingController extends Controller
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->settings->describe()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array', 'min:1'],
        ]);

        // Unknown keys are refused by the service with a 422 naming the key,
        // rather than being ignored: a setting somebody believes they changed
        // and which does nothing is the worst possible outcome here.
        $this->settings->put($data['settings'], $this->currentUser()->getKey());

        return response()->json([
            'message' => 'Settings saved.',
            'data' => $this->settings->describe(),
        ]);
    }
}
