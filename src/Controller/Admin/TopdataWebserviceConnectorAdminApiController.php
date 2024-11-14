<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Controller\Admin;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Topdata\TopdataConnectorSW6\Constants\GlobalPluginConstants;
use Topdata\TopdataConnectorSW6\Helper\TopdataWebserviceClient108;
use Topdata\TopdataConnectorSW6\Service\ConfigCheckerService;
use Topdata\TopdataConnectorSW6\Service\TopdataBrandService;

/**
 * 10/2024 renamed TopdataConnectorController --> TopdataWebserviceConnectorAdminApiController
 */
#[Route(defaults: ['_routeScope' => ['administration']])]
class TopdataWebserviceConnectorAdminApiController extends AbstractController
{

    public function __construct(
        private readonly SystemConfigService   $systemConfigService,
        private readonly LoggerInterface       $logger,
        private readonly ContainerBagInterface $containerBag,
        private readonly ConfigCheckerService  $configCheckerService,
        private readonly TopdataBrandService   $topdataBrandService,
    )
    {
    }

    /**
     * Test the connector configuration.
     */
    #[Route(
        path: '/api/topdata/connector-test',
        name: 'api.action.topdata.connector-test',
        methods: ['GET']
    )]
    public function connectorTestAction(): JsonResponse
    {
        $additionalData = '';
        $config = $this->systemConfigService->get('TopdataConnectorSW6.config');
        if ($this->configCheckerService->isConfigEmpty()) {
            $credentialsValid = 'no';
            $additionalData .= GlobalPluginConstants::ERROR_MESSAGE_NO_WEBSERVICE_CREDENTIALS;
        }

        try {
            $webservice = new TopdataWebserviceClient108(
                $config['apiBaseUrl'],
                $config['apiUsername'],
                $config['apiKey'],
                $config['apiSalt'],
                $config['apiLanguage']
            );
            $info = $webservice->getUserInfo();

            if (isset($info->error)) {
                $credentialsValid = 'no';
                $additionalData .= $info->error[0]->error_message;
            } else {
                $credentialsValid = 'yes';
                $additionalData = $info;
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->logger->error($errorMessage);
            $credentialsValid = 'no';
            $additionalData .= $errorMessage;
        }

        return new JsonResponse([
            'credentialsValid' => $credentialsValid,
            'additionalData'   => $additionalData,
        ]);
    }

    /**
     * Load all enabled brands.
     */
    #[Route(
        path: '/api/topdata/load-brands',
        name: 'api.action.topdata.load-brands',
        methods: ['GET']
    )]
    public function loadBrands(Request $request, Context $context): JsonResponse
    {
        $result = $this->topdataBrandService->getEnabledBrands();
        $result['additionalData'] = 'success';

        return new JsonResponse($result);
    }

    /**
     * Save primary brands.
     */
    #[Route(
        path: '/api/topdata/save-primary-brands',
        name: 'api.action.topdata.save-primary-brands',
        methods: ['POST']
    )]
    public function savePrimaryBrands(Request $request, Context $context): JsonResponse
    {
        $params = $request->request->all();
        $success = $this->topdataBrandService->savePrimaryBrands($params['primaryBrands'] ?? null);

        return new JsonResponse([
            'success' => $success ? 'true' : 'false',
        ]);
    }

    /**
     * Get active Topdata plugins.
     * TODO: move this into a service in the TopdataControlCenterSW6 plugin
     * TODO: remove additionalData (it is just an empty string)
     */
    #[Route(
        path: '/api/topdata/connector-plugins',
        name: 'api.action.topdata.connector-plugins',
        methods: ['GET']
    )]
    public function activeTopdataPlugins(Request $request, Context $context): JsonResponse
    {
        $activePlugins = [];
        $additionalData = '';

        $allActivePlugins = $this->containerBag->get('kernel.active_plugins');

        foreach ($allActivePlugins as $pluginClassName => $val) {
            $pluginClass = explode('\\', $pluginClassName);
            if ($pluginClass[0] == 'Topdata') {
                $activePlugins[] = array_pop($pluginClass);
            }
        }

        return new JsonResponse([
            'activePlugins'  => $activePlugins,
            'additionalData' => $additionalData,
        ]);
    }

    /**
     * Get the plugin's config.
     * TODO: rename .. it returns more than just the credentials
     */
    #[Route(
        path: '/api/_action/connector/connector-credentials-get',
        name: 'api.action.connector.connector.credentials.get',
        methods: ['GET']
    )]
    public function getCredentialsAction(): JsonResponse
    {
        $config = $this->systemConfigService->get('TopdataConnectorSW6.config');

        return new JsonResponse($config);
    }
}
