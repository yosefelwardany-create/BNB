<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Payments\Models\Expense;
use App\Domain\Payments\Services\ExpenseService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Costs incurred against properties.
 *
 * The lifecycle is deliberately explicit — draft, approved, paid — rather than
 * a single "create it and it's real" step, because an expense eventually
 * appears on somebody's statement as a deduction from their income. Somebody
 * has to have decided that, and the record has to show who.
 */
class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Expense::class);

        $query = Expense::query()->with(['vendor', 'property']);

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->string('property_id')->toString());
        }

        if ($request->filled('owner_id')) {
            $query->where('owner_id', $request->string('owner_id')->toString());
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->string('vendor_id')->toString());
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($request->filled('billable_to')) {
            $query->where('billable_to', $request->string('billable_to')->toString());
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->inPeriod($request->date('from')->toDateString(), $request->date('to')->toDateString());
        }

        // The queue an accountant works from: approved, the owner's to bear,
        // and not yet swept into a statement.
        if ($request->boolean('awaiting_statement')) {
            $query->availableForStatement();
        }

        return ExpenseResource::collection(
            $query->orderByDesc('expense_date')->paginate($this->perPage()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Expense::class);

        $expense = $this->expenses->create($request->validate($this->rules()));

        return (new ExpenseResource($expense))->response()->setStatusCode(201);
    }

    public function show(Expense $expense): ExpenseResource
    {
        $this->authorize('view', $expense);

        return new ExpenseResource($expense->load(['vendor', 'property', 'task']));
    }

    public function update(Request $request, Expense $expense): ExpenseResource
    {
        $this->authorize('update', $expense);

        return new ExpenseResource(
            $this->expenses->update($expense, $request->validate($this->rules($expense))),
        );
    }

    /**
     * Approve a cost, and post it to the ledger.
     */
    public function approve(Expense $expense): ExpenseResource
    {
        $this->authorize('approve', $expense);

        return new ExpenseResource($this->expenses->approve($expense));
    }

    public function reject(Request $request, Expense $expense): ExpenseResource
    {
        $this->authorize('approve', $expense);

        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return new ExpenseResource($this->expenses->reject($expense, $data['reason'] ?? null));
    }

    /**
     * Record that the vendor has actually been paid.
     */
    public function pay(Request $request, Expense $expense): ExpenseResource
    {
        $this->authorize('pay', $expense);

        $data = $request->validate(['method' => ['sometimes', 'nullable', 'string', 'max:32']]);

        return new ExpenseResource($this->expenses->markPaid($expense, $data['method'] ?? null));
    }

    /**
     * Withdraw a cost.
     *
     * Soft-deleted, never removed: a rejected or mistaken expense is still the
     * answer to "why did you charge me for this", and an owner who queries a
     * statement is entitled to the whole trail.
     */
    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        $expense->delete();

        return response()->json(['message' => 'The expense has been withdrawn.']);
    }

    /**
     * Totals for a period, split by who bears them.
     *
     * Answers the question an operator actually has — "what is this portfolio
     * costing, and how much of it are we absorbing" — in one call rather than
     * by paginating every row and adding them up client-side.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $currency = $this->organization()->base_currency;

        $query = Expense::query()->whereIn('status', [Expense::APPROVED, Expense::PAID]);

        if ($request->filled('from') && $request->filled('to')) {
            $query->inPeriod($request->date('from')->toDateString(), $request->date('to')->toDateString());
        }

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->string('property_id')->toString());
        }

        $rows = $query->selectRaw(
            'billable_to, category, count(*) as entries, '
            .'sum(amount + tax_amount + markup_amount) as total'
        )->groupBy('billable_to', 'category')->get();

        $byBearer = [];
        $byCategory = [];
        $total = Money::zero($currency);

        foreach ($rows as $row) {
            $amount = Money::of((int) $row->total, $currency);

            $byBearer[$row->billable_to] = isset($byBearer[$row->billable_to])
                ? $byBearer[$row->billable_to]->add($amount)
                : $amount;

            $byCategory[$row->category] = isset($byCategory[$row->category])
                ? $byCategory[$row->category]->add($amount)
                : $amount;

            $total = $total->add($amount);
        }

        return response()->json([
            'data' => [
                'currency' => $currency,
                'total' => $total->jsonSerialize(),
                'by_bearer' => array_map(fn (Money $m): array => $m->jsonSerialize(), $byBearer),
                'by_category' => array_map(fn (Money $m): array => $m->jsonSerialize(), $byCategory),
                'entries' => (int) $rows->sum('entries'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Expense $expense = null): array
    {
        $creating = $expense === null;

        return [
            'property_id' => [$creating ? 'required' : 'sometimes', 'string', 'size:26'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'vendor_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'task_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'expense_date' => [$creating ? 'required' : 'sometimes', 'date'],
            'category' => [$creating ? 'required' : 'sometimes', 'string', 'max:48'],
            'description' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            // Minor units, like every other amount in this API.
            'amount' => [$creating ? 'required' : 'sometimes', 'integer', 'min:0'],
            'tax_amount' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'billable_to' => ['sometimes', 'string', 'in:owner,guest,manager'],
            'markup_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'receipt_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
