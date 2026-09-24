<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reporting;

use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Services\ReportDestinationRegistry;
use App\Domain\Reports\Services\ReportExporter;
use App\Domain\Reports\Services\ReportRegistry;
use App\Domain\Reports\Services\ReportRunner;
use App\Http\Controllers\Controller;
use App\Http\Resources\SavedReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reports somebody set up once and wants again.
 *
 * A saved report stores a report key and parameters, never a query. That is
 * the boundary that stops "save a report" becoming "run arbitrary SQL against
 * a multi-tenant database".
 *
 * A scheduled report runs as the person who saved it, and stops running if
 * they lose the permission it needs. Somebody moved off the finance team
 * should stop receiving the finance report, not keep receiving it because they
 * once set one up.
 */
class SavedReportController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly ReportExporter $exporter,
        private readonly ReportDestinationRegistry $destinations,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('reports.view');

        $query = SavedReport::query()
            ->with('creator:id,first_name,last_name')
            // Own plus shared: a list where every colleague's experiments
            // appear is a list nobody reads.
            ->visibleTo($this->currentUser());

        if ($request->filled('report_key')) {
            $query->where('report_key', $request->string('report_key')->toString());
        }

        if ($request->boolean('scheduled_only')) {
            $query->scheduled();
        }

        return SavedReportResource::collection(
            $query->orderBy('name')->paginate($this->perPage()),
        );
    }

    /**
     * Where a report can be sent, and what each destination needs.
     *
     * Served from the registry rather than hard-coded in the interface, so a
     * destination added here appears in the form without a frontend change —
     * and so a form can never offer one that does not exist.
     */
    public function destinations(): JsonResponse
    {
        $this->authorize('reports.view');

        return response()->json(['data' => $this->destinations->catalogue()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('reports.view');

        $data = $request->validate($this->rules());

        $this->assertRunnable($data['report_key']);
        $this->assertDestinationsUsable($data);
        $this->assertSchedulable($data);

        $saved = new SavedReport;
        $saved->fill($data);
        $saved->organization_id = $this->organization()->getKey();
        $saved->created_by_id = auth()->id();
        $saved->save();

        // Computed after the save, because it depends on the stored schedule
        // and timezone.
        $saved->forceFill(['next_run_at' => $this->runner->nextRunAt($saved)])->save();

        return (new SavedReportResource($saved->fresh()))->response()->setStatusCode(201);
    }

    public function show(SavedReport $savedReport): SavedReportResource
    {
        $this->authorizeAccess($savedReport);

        return new SavedReportResource($savedReport->load('creator'));
    }

    public function update(Request $request, SavedReport $savedReport): SavedReportResource
    {
        $this->authorizeOwnership($savedReport);

        $data = $request->validate($this->rules($savedReport));

        if (isset($data['report_key'])) {
            $this->assertRunnable($data['report_key']);
        }

        $this->assertDestinationsUsable($data);
        $this->assertSchedulable($data + [
            'report_key' => $savedReport->report_key,
            'recipients' => $savedReport->recipients,
            'destinations' => $savedReport->destinations,
        ]);

        $savedReport->fill($data)->save();
        $savedReport->forceFill(['next_run_at' => $this->runner->nextRunAt($savedReport)])->save();

        return new SavedReportResource($savedReport->fresh());
    }

    /**
     * Run a saved report now.
     */
    public function run(SavedReport $savedReport): JsonResponse
    {
        $this->authorizeAccess($savedReport);

        ['report' => $report, 'result' => $result] = $this->runner->runSaved($savedReport);

        return response()->json(
            $this->exporter->toArray($report, $result)
                + ['parameters' => $savedReport->parameters()->toArray()],
        );
    }

    public function destroy(SavedReport $savedReport): JsonResponse
    {
        $this->authorizeOwnership($savedReport);

        $savedReport->delete();

        return response()->json(['message' => 'The saved report has been removed.']);
    }

    /**
     * Whether the caller may read this saved report.
     */
    private function authorizeAccess(SavedReport $saved): void
    {
        $user = $this->currentUser();

        abort_unless(
            $saved->is_shared
                || $saved->created_by_id === $user->getKey()
                || $user->isPlatformAdmin(),
            404,
            'That saved report does not exist.',
        );
    }

    /**
     * Whether the caller may change it.
     *
     * Sharing a report does not hand over control of it: a colleague who can
     * read somebody's saved view should not be able to repoint its schedule at
     * their own recipients.
     */
    private function authorizeOwnership(SavedReport $saved): void
    {
        $user = $this->currentUser();

        abort_unless(
            $saved->created_by_id === $user->getKey() || $user->isPlatformAdmin(),
            403,
            'Only the person who saved this report can change it.',
        );
    }

    private function assertRunnable(string $key): void
    {
        abort_unless(
            $this->registry->has($key),
            422,
            sprintf('There is no report named "%s".', $key),
        );

        // Saving a report you cannot run would be a way to have somebody else
        // run it for you.
        $report = $this->registry->make($key);

        $this->authorize($report->permission());
    }

    /**
     * Each destination checks its own configuration before it is stored.
     *
     * Up front rather than at run time, because a schedule that fails at three
     * in the morning on a typo in a URL is a schedule nobody finds out about
     * for a week.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertDestinationsUsable(array $data): void
    {
        $destinations = $data['destinations'] ?? null;

        if (blank($destinations)) {
            return;
        }

        $problems = [];

        foreach ((array) $destinations as $index => $destination) {
            $type = (string) ($destination['type'] ?? '');

            if (! $this->destinations->has($type)) {
                $problems[sprintf('destinations.%s.type', $index)] = [sprintf(
                    'There is no destination named "%s". Available: %s.',
                    $type,
                    implode(', ', $this->destinations->keys()),
                )];

                continue;
            }

            $found = $this->destinations->make($type)->problemsWith((array) $destination);

            if ($found !== []) {
                $problems[sprintf('destinations.%s', $index)] = $found;
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertSchedulable(array $data): void
    {
        $cron = $data['schedule_cron'] ?? null;

        if ($cron === null) {
            return;
        }

        abort_unless(
            $this->runner->isValidSchedule($cron),
            422,
            'That schedule is not a valid cron expression.',
        );

        abort_if(
            blank($data['recipients'] ?? null) && blank($data['destinations'] ?? null),
            422,
            'A scheduled report needs somewhere to go. A schedule that delivers to nobody looks identical to one that is broken.',
        );

        $this->authorize('reports.schedule');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?SavedReport $saved = null): array
    {
        $creating = $saved === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'report_key' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],

            // Whatever the report accepts. Validated again at run time against
            // the report's own rules, so nothing here has to be trusted.
            'parameters' => ['sometimes', 'nullable', 'array'],

            'is_shared' => ['sometimes', 'boolean'],

            'schedule_cron' => ['sometimes', 'nullable', 'string', 'max:64'],
            'schedule_timezone' => ['sometimes', 'nullable', 'timezone'],
            'recipients' => ['sometimes', 'nullable', 'array', 'max:50'],
            'recipients.*' => ['email'],

            // Shape only. Each destination validates its own configuration,
            // because only a webhook knows what a signing secret has to look
            // like and only storage knows what a retention period may be.
            'destinations' => ['sometimes', 'nullable', 'array', 'max:10'],
            'destinations.*' => ['array'],
            'destinations.*.type' => ['required', 'string', 'max:32'],

            'format' => ['sometimes', Rule::in(['csv', 'json'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
