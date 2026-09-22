<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reporting;

use App\Domain\Reports\Contracts\ReportInterface;
use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Reports\Services\ReportExporter;
use App\Domain\Reports\Services\ReportRegistry;
use App\Domain\Reports\Services\ReportRunner;
use App\Domain\Users\Services\AccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Running reports.
 *
 * The catalogue is filtered to what the caller may actually run, rather than
 * listing everything and refusing later. A list of reports somebody cannot
 * open is an invitation to ask why, and the answer — "you are not allowed to
 * see owner payouts" — is a conversation better had by the person who set the
 * roles.
 *
 * Each report declares its own permission. An occupancy report and an owner
 * profit-and-loss are not the same disclosure, and a single `reports.view`
 * would make them so.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly ReportExporter $exporter,
        private readonly AccessControl $access,
    ) {}

    /**
     * The reports this caller can run.
     */
    public function index(): JsonResponse
    {
        $this->authorize('reports.view');

        $user = $this->currentUser();

        $reports = array_values(array_filter(
            $this->registry->all(),
            fn (ReportInterface $report): bool => $user->isPlatformAdmin()
                || $this->access->allows($user, $report->permission()),
        ));

        return response()->json([
            'data' => array_map(fn (ReportInterface $report): array => [
                'key' => $report->key(),
                'name' => $report->name(),
                'description' => $report->description(),
                'category' => $report->category(),
                'columns' => $report->columns(),
            ], $reports),
        ]);
    }

    /**
     * Run a report and return its rows.
     */
    public function run(Request $request, string $key): JsonResponse
    {
        $this->authorize('reports.view');

        abort_unless($this->registry->has($key), 404, sprintf('There is no report named "%s".', $key));

        $report = $this->registry->make($key);

        $parameters = $this->parameters($request, $report);

        $result = $this->runner->run($key, $parameters, $this->currentUser());

        return response()->json(
            $this->exporter->toArray($report, $result)
                + ['parameters' => $parameters->toArray()],
        );
    }

    /**
     * Run a report and stream it as a file.
     *
     * Streamed rather than built in memory: a year of arrivals across a large
     * portfolio is tens of thousands of rows, and holding the whole CSV in a
     * string before sending it is how a report takes the process down.
     */
    public function export(Request $request, string $key): StreamedResponse
    {
        $this->authorize('reports.export');

        abort_unless($this->registry->has($key), 404, sprintf('There is no report named "%s".', $key));

        $report = $this->registry->make($key);
        $parameters = $this->parameters($request, $report);
        $result = $this->runner->run($key, $parameters, $this->currentUser());

        $csv = $this->exporter->toCsv($report, $result);

        $filename = sprintf(
            '%s-%s-to-%s.csv',
            $report->key(),
            $parameters->from->toDateString(),
            $parameters->to->toDateString(),
        );

        return response()->streamDownload(
            function () use ($csv): void {
                // A BOM, so Excel opens a UTF-8 file as UTF-8 rather than as
                // whatever the machine's locale happens to be. Without it,
                // every accented property name arrives mangled.
                echo "\xEF\xBB\xBF".$csv;
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * The period and filters, validated against what the report declares.
     */
    private function parameters(Request $request, ReportInterface $report): ReportParameters
    {
        $data = $request->validate(array_merge([
            // A named period or explicit dates. Named periods are resolved
            // now rather than stored, so "last month" always means last month.
            'period' => ['sometimes', 'string', 'in:today,yesterday,last_7_days,last_30_days,this_month,last_month,this_year,last_year,next_30_days,next_90_days'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'property_ids' => ['sometimes', 'array'],
            'property_ids.*' => ['string', 'size:26'],
        ], $report->parameterRules()));

        $parameters = ReportParameters::fromArray($data);

        abort_if(
            $parameters->days() > 1100,
            422,
            'A report may cover at most three years. Narrow the period or export in parts.',
        );

        return $parameters;
    }
}
