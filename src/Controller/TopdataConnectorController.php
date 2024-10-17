<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Controller;

use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Topdata\TopdataConnectorSW6\Command\ProductsCommand;
use Topdata\TopdataConnectorSW6\Helper\TopdataWebserviceClient;
use Topdata\TopdataConnectorSW6\Service\ConfigCheckerService;

/**
 * @Route(defaults={"_routeScope"={"administration"}})
 */
#[Route(defaults: ['_routeScope' => 'administration'])]
class TopdataConnectorController extends AbstractController
{
    private SystemConfigService $systemConfigService;
    private Logger $logger;
    private ContainerBagInterface $containerBag;
    private Connection $connection;
    private EntityRepository $topdataBrandRepository;
    private ConfigCheckerService $configCheckerService;

    public function __construct(
        SystemConfigService $systemConfigService,
        Logger $logger,
        ContainerBagInterface $containerBag,
        Connection $connection,
        EntityRepository $topdataBrandRepository
    ) {
        $this->systemConfigService    = $systemConfigService;
        $this->logger                 = $logger;
        $this->containerBag           = $containerBag;
        $this->connection             = $connection;
        $this->topdataBrandRepository = $topdataBrandRepository;
    }

    /**
     * @Route("/api/topdata/connector-test", name="api.action.topdata.connector-test", methods={"GET"})
     */
    #[Route('/api/topdata/connector-test', name: 'api.action.topdata.connector-test', methods: ['GET'])]
    public function connectorTestAction(): JsonResponse
    {
        $additionalData = '';
        $config         = $this->systemConfigService->get('TopdataConnectorSW6.config');
        if ($this->configCheckerService->isConfigEmpty()) {
            $credentialsValid = 'no';
            $additionalData .= 'Fill in the connection parameters in admin: Extensions > My Extensions > Topdata Webservice Connector > [...] > Configure';
        }

        try {
            $webservice = new TopdataWebserviceClient($this->logger, $config['apiUsername'], $config['apiKey'], $config['apiSalt'], $config['apiLanguage']);
            $info       = $webservice->getUserInfo();

            if (isset($info->error)) {
                $credentialsValid = 'no';
                $additionalData .= $info->error[0]->error_message;
            } else {
                $credentialsValid = 'yes';
                $additionalData   = $info;
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
     * @Route("/api/topdata/load-brands", name="api.action.topdata.load-brands", methods={"GET"})
     */
    #[Route('/api/topdata/load-brands', name: 'api.action.topdata.load-brands', methods: ['GET'])]
    public function loadBrands(Request $request, Context $context): JsonResponse
    {
        $allBrands     = [];
        $primaryBrands = [];
        $brands        = $this->connection->createQueryBuilder()
            ->select('LOWER(HEX(id)) as id, label as name, sort')
            ->from('topdata_brand')
            ->where('is_enabled = 1')
            ->orderBy('label')
            ->execute()
            ->fetchAllAssociative();

        foreach ($brands as $brand) {
            $allBrands[$brand['id']] = $brand['name'];
            if ($brand['sort'] == 1) {
                $primaryBrands[$brand['id']] = $brand['name'];
            }
        }

        return new JsonResponse([
            'brands'         => $allBrands,
            'primary'        => $primaryBrands,
            'brandsCount'    => count($allBrands),
            'primaryCount'   => count($primaryBrands),
            'additionalData' => 'success',
        ]);
    }

    /**
     * @Route("/api/topdata/save-primary-brands", name="api.action.topdata.save-primary-brands", methods={"POST"})
     */
    #[Route('/api/topdata/save-primary-brands', name: 'api.action.topdata.save-primary-brands', methods: ['POST'])]
    public function savePrimaryBrands(Request $request, Context $context): JsonResponse
    {
        $params = $request->request->all();
        if (!isset($params['primaryBrands'])) {
            return new JsonResponse([
                'success' => 'false',
            ]);
        }

        $brands = $params['primaryBrands'];
        $this->connection->executeStatement('UPDATE topdata_brand SET sort = 0');

        if ($brands) {
            foreach ($brands as $key => $brandId) {
                if (preg_match('/^[0-9a-f]{32}$/', $brandId)) {
                    $brands[$key] = '0x' . $brandId;
                }
            }

            $this->connection->executeStatement('UPDATE topdata_brand SET sort = 1 WHERE id IN (' . implode(',', $brands) . ')');
        }

        return new JsonResponse([
            'success' => 'true',
        ]);
    }

    /**
     * @Route("/api/topdata/connector-plugins", name="api.action.topdata.connector-plugins", methods={"GET"})
     */
    #[Route('/api/topdata/connector-plugins', name: 'api.action.topdata.connector-plugins', methods: ['GET'])]
    public function activeTopdataPlugins(Request $request, Context $context): JsonResponse
    {
        $activePlugins  = [];
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
     * @Route("/api/_action/connector/connector-credentials-get", name="api.action.connector.connector.credentials.get", methods={"GET"})
     */
    #[Route('/api/_action/connector/connector-credentials-get', name: 'api.action.connector.connector.credentials.get', methods: ['GET'])]
    public function getCredentialsAction(): JsonResponse
    {
        $config = $this->systemConfigService->get('TopdataConnectorSW6.config');

        return new JsonResponse($config);
    }

    /**
     * @Route("/api/topdata/connector-install-demodata", name="api.action.topdata.connector-install-demodata", methods={"GET"})
     */
    #[Route('/api/topdata/connector-install-demodata', name: 'api.action.topdata.connector-install-demodata', methods: ['GET'])]
    public function installDemoData(Request $request, Context $context): JsonResponse
    {
        $manufacturerRepository = $this->container->get('product_manufacturer.repository');
        $productRepository      = $this->container->get('product.repository');
        $connection             = $this->container->get('Doctrine\DBAL\Connection');
        $productsService        = new ProductsCommand($manufacturerRepository, $productRepository, $connection);

        $result = $productsService->installDemoData();

        return new JsonResponse($result);
    }
}
