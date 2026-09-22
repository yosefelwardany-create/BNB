<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Properties;

use App\Domain\Properties\Models\Portfolio;
use App\Http\Controllers\Controller;
use App\Http\Resources\PortfolioResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PortfolioController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Portfolio::class);

        $query = Portfolio::query()->withCount('properties');

        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        return PortfolioResource::collection($query->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Portfolio::class);

        $data = $request->validate($this->rules());

        $portfolio = Portfolio::query()->create($data);

        return (new PortfolioResource($portfolio))->response()->setStatusCode(201);
    }

    public function show(Portfolio $portfolio): PortfolioResource
    {
        $this->authorize('view', $portfolio);

        return new PortfolioResource($portfolio->loadCount('properties'));
    }

    public function update(Request $request, Portfolio $portfolio): PortfolioResource
    {
        $this->authorize('update', $portfolio);

        $portfolio->update($request->validate($this->rules($portfolio)));

        return new PortfolioResource($portfolio->loadCount('properties'));
    }

    /**
     * Remove a portfolio.
     *
     * The properties inside it are not touched — they simply lose the
     * grouping. Deleting a portfolio must never cascade into the estate.
     */
    public function destroy(Portfolio $portfolio): JsonResponse
    {
        $this->authorize('delete', $portfolio);

        $count = $portfolio->properties()->count();

        $portfolio->properties()->update(['portfolio_id' => null]);
        $portfolio->delete();

        return response()->json([
            'message' => $count > 0
                ? sprintf('Portfolio removed. %d propert%s kept and left ungrouped.', $count, $count === 1 ? 'y was' : 'ies were')
                : 'Portfolio removed.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Portfolio $portfolio = null): array
    {
        return [
            'name' => [$portfolio === null ? 'required' : 'sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'color' => ['sometimes', 'nullable', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
