<?php

namespace HiEvents\Http\Actions\Reports;

use HiEvents\DomainObjects\Enums\ReportTypes;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Middleware\EnforceApiKeyScope;
use HiEvents\Http\Request\Report\GetReportRequest;
use HiEvents\Services\Application\Handlers\Reports\DTO\GetReportDTO;
use HiEvents\Services\Application\Handlers\Reports\GetReportHandler;
use HiEvents\Services\Domain\Auth\AuthUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class GetReportAction extends BaseAction
{
    public function __construct(
        private readonly GetReportHandler $reportHandler,
        private readonly AuthUserService $authUserService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(GetReportRequest $request, int $eventId, string $reportType): JsonResponse
    {
        if ($reportType === ReportTypes::PROMO_CODES_REPORT->value && $this->authUserService->isApiKeyRequest()) {
            return $this->jsonResponse([
                'message' => __('This route is not available to API keys.'),
                'error_code' => EnforceApiKeyScope::ERROR_CODE,
                'ability' => null,
            ], Response::HTTP_FORBIDDEN);
        }

        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $this->validateDateRange($request);

        if (! in_array($reportType, ReportTypes::valuesArray(), true)) {
            throw new BadRequestHttpException(__('Invalid report type.'));
        }

        $reportData = $this->reportHandler->handle(
            reportData: new GetReportDTO(
                eventId: $eventId,
                reportType: ReportTypes::from($reportType),
                startDate: $request->validated('start_date'),
                endDate: $request->validated('end_date'),
                occurrenceId: $request->validated('occurrence_id') ? (int) $request->validated('occurrence_id') : null,
            ),
        );

        return $this->jsonResponse($reportData);
    }

    /**
     * @throws ValidationException
     */
    private function validateDateRange(GetReportRequest $request): void
    {
        $startDate = $request->validated('start_date');
        $endDate = $request->validated('end_date');

        $diffInDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate));

        if ($diffInDays > 370) {
            throw ValidationException::withMessages([
                'start_date' => __('Date range must be less than 370 days.'),
            ]);
        }
    }
}
