<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Topdata\TopdataConnectorSW6\Service\TopdataReportService;

/**
 * Handles authentication-related actions.
 */
class TopdataAuthController extends AbstractTopdataApiController
{
    public function __construct(
        private readonly TopdataReportService $reportService
    ) {
    }

    /**
     * Handles the login action for accessing reports.
     *
     * @param Request $request The HTTP request.
     * @return Response The HTTP response.
     */
    #[Route(
        path: '/topdata-foundation/auth/login',
        name: 'topdata.foundation.auth.login',
        defaults: ['_routeScope' => ['storefront']],
        methods: ['GET', 'POST'],
        requirements: ['_format' => 'html']
    )]
    public function loginAction(Request $request): Response
    {
        // ---- Handle POST request (login attempt)
        if ($request->isMethod('POST')) {
            $password = $request->request->get('password');
            // ---- Validate the password
            if ($this->reportService->validateReportsPassword($password)) {
                $request->getSession()->set('topdata_reports_authenticated', true);

                return $this->redirectToRoute('topdata.foundation.reports');
            }
            $this->addFlash('error', 'Invalid password');
        }
        // ---- Render the login form
        return $this->render('@TopdataFoundationSW6/storefront/page/content/login.html.twig');
    }

    /**
     * Handles the logout action.
     *
     * @param Request $request The HTTP request.
     * @return Response The HTTP response.
     */
    #[Route(
        path: '/topdata-foundation/auth/logout',
        name: 'topdata.foundation.auth.logout',
        defaults: ['_routeScope' => ['storefront']],
        methods: ['GET'],
        requirements: ['_format' => 'html']
    )]
    public function logoutAction(Request $request): Response
    {
        // ---- Remove the authentication flag from the session
        $request->getSession()->remove('topdata_reports_authenticated');
        $this->addFlash('success', 'You have been logged out.');
        return $this->redirectToRoute('topdata.foundation.auth.login');
    }
}