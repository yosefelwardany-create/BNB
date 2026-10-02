<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Channels;

use App\Domain\Users\Services\AccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HostexFinancialController extends Controller
{
    public function index(Request $request, AccessControl $access)
    {
        $restricted = $access->restrictedPropertyIds($this->currentUser());
        $query = DB::table('hostex_transactions')->where('organization_id', $this->organization()->id)
            ->when($restricted !== null, fn ($q) => $q->whereIn('property_id', $restricted))
            ->when($request->filled('property_id'), fn ($q) => $q->where('property_id', $request->string('property_id')->toString()));
        $page = $query->orderByDesc('synced_at')->orderBy('id')->paginate($this->perPage());
        $page->through(fn ($row) => [
            'id' => (string) $row->id, 'external_id' => $row->external_id, 'property_id' => $row->property_id,
            'channel_account_id' => $row->channel_account_id, 'synced_at' => $row->synced_at,
        ] + json_decode($row->data, true));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }
}
