<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Controller;

use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Topdata\TopdataConnectorSW6\Service\TopdataReportService;

/**
 * Handles report-related actions.
 */
class TopdataReportController extends AbstractTopdataApiController
{
    public function __construct(
        private readonly TopdataReportService $reportService
    ) {
    }

    /**
     * Retrieves and displays the latest reports.
     *
     * @param Request $request The HTTP request.
     * @return Response The HTTP response.
     * @throws Exception
     */
    #[Route(
        path: '/topdata-foundation/reports',
        name: 'topdata.foundation.reports',
        defaults: ['_routeScope' => ['storefront']],
        methods: ['GET'],
        requirements: ['_format' => 'html']
    )]
    public function getLatestReportsAction(Request $request): Response
    {
        try {
            // ---- Check if the user is authenticated
            if (!$request->getSession()->get('topdata_reports_authenticated', false)) {
                $this->addFlash('error', 'Not authenticated');
                return $this->redirectToRoute('topdata.foundation.auth.login');
            }

            // ---- Render the reports template with the latest reports
            return $this->render('@TopdataFoundationSW6/storefront/page/content/reports.html.twig', [
                'reports' => $this->reportService->getLatestReports()
            ]);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Retrieves and displays the details of a specific report.
     *
     * @param Request $request The HTTP request.
     * @param string $id The ID of the report.
     * @return Response The HTTP response.
     * @throws Exception
     */
    #[Route(
        path: '/topdata-foundation/report/{id}',
        name: 'topdata.foundation.report.detail',
        defaults: ['_routeScope' => ['storefront']],
        methods: ['GET'],
        requirements: ['_format' => 'html']
    )]
    public function getReportDetailAction(Request $request, string $id): Response
    {
        try {
            // ---- Check if the user is authenticated
            if (!$request->getSession()->get('topdata_reports_authenticated', false)) {
                $this->addFlash('error', 'Not authenticated');
                return $this->redirectToRoute('topdata.foundation.auth.login');
            }

            // ---- Retrieve the report by ID
            $report = $this->reportService->getReportById($id);

            // ---- Check if the report exists
            if (!$report) {
                $this->addFlash('error', 'Report not found');
                return $this->redirectToRoute('topdata.foundation.reports');
            }

            // ---- Render the detailed report template
            return $this->render('@TopdataFoundationSW6/storefront/page/content/detailed_report.html.twig', [
                'report' => $report,
            ]);

        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
}