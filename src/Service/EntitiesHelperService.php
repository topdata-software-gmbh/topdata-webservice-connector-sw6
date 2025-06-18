<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\PropertyGroupDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilUuid;
use Topdata\TopdataFoundationSW6\Service\LocaleHelperService;

/**
 * Note: this is also used by the TopdataTopfinderProSW6 plugin.
 *
 * 04/2024 EntitiesHelper --> EntitiesHelperService
 */
class EntitiesHelperService
{
    private ?array $categoryTree = null;
    private ?array $manufacturers = null;
    private ?string $defaultCmsListingPageId = null;
    private mixed $temp;
    private readonly string $systemDefaultLocaleCode;
    private readonly Context $context;

    public function __construct(
        private readonly Connection          $connection,
        private readonly EntityRepository    $categoryRepository,
        private readonly EntityRepository    $productManufacturerRepository,
        private readonly LocaleHelperService $localeHelperService,
    )
    {
        $this->systemDefaultLocaleCode = $this->localeHelperService->getLocaleCodeOfSystemLanguage();
        $this->context = Context::createDefaultContext();
    }


    private function buildCategorySubTree(?string $categoryId, $categories): array
    {
        $ret = [];
        foreach ($categories as $category) {
            if ($category->getParentId() === $categoryId) {
                $ret[] = [
                    'id'     => $category->getId(),
                    'name'   => $category->getName(),
                    'childs' => $this->buildCategorySubTree($category->getId(), $categories),
                ];
            }
        }

        return $ret;
    }

    protected function loadCategoryTree()
    {
        $categories = $this->categoryRepository->search(new Criteria(), $this->context)->getEntities();
        $this->categoryTree = $this->buildCategorySubTree(null, $categories);
    }

    public function getCategoryTree(): array
    {
        if (null === $this->categoryTree) {
            $this->loadCategoryTree();
        }

        return $this->categoryTree;
    }

    private function findCategoryBranchByParam(string $paramValue, array $categories, string $paramName, bool $inDepth = true): ?array
    {
        foreach ($categories as $cat) {
            if ($cat[$paramName] == $paramValue) {
                return $cat;
            } elseif (count($cat['childs']) && $inDepth) {
                $found = $this->findCategoryBranchByParam($paramValue, $cat['childs'], $paramName, $inDepth);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function findInBranch(string $name, array $branch): ?array
    {
        foreach ($branch['childs'] as $child) {
            if ($child['name'] == $name) {
                return $child;
            }
        }

        return null;
    }

    private function prepareCategoriesBranchData(array $categoriesChain): ?array
    {
        if (!$categoriesChain) {
            return null;
        }

        $this->temp = Uuid::randomHex();

        $ret = [
            'id'                    => $this->temp,
            'cmsPageId'             => $this->getDefaultCmsListingPageId(),
            'active'                => true,
            'displayNestedProducts' => true,
            'visible'               => true,
            'type'                  => 'page',
            'name'                  => [
                $this->systemDefaultLocaleCode => $categoriesChain[0]['waregroup'],
            ],
        ];

        $child = $this->prepareCategoriesBranchData(array_slice($categoriesChain, 1));

        if ($child) {
            $ret['children'] = [
                $child,
            ];
        }

        return $ret;
    }

    /**
     * @param array $categoriesChain
     * @param string $parentId
     * @return string Id of a last created child category
     */
    private function createBranch(array $categoriesChain, ?string $parentId): string
    {
        $this->temp = Uuid::randomHex();

        $data = [
            'id'                    => $this->temp,
            'cmsPageId'             => $this->getDefaultCmsListingPageId(),
            'active'                => true,
            'displayNestedProducts' => true,
            'visible'               => true,
            'type'                  => 'page',
            'name'                  => [
                $this->systemDefaultLocaleCode => $categoriesChain[0]['waregroup'],
            ],
        ];
        if ($parentId) {
            $data['parentId'] = $parentId;
        }

        $child = $this->prepareCategoriesBranchData(array_slice($categoriesChain, 1));

        if ($child) {
            $data['children'] = [
                $child,
            ];
        }

        $this->categoryRepository->create([$data], $this->context);

        return $this->temp;
    }

    public function getCategoryId(array $categoriesChain, string $parentCategoryId): string
    {
        if (null === $this->categoryTree) {
            $this->loadCategoryTree();
        }

        if ($parentCategoryId) {
            $branch = $this->findCategoryBranchByParam($parentCategoryId, $this->categoryTree, 'id');
            if (!$branch) {
                return '';
            }
            $parentId = $parentCategoryId;
            foreach ($categoriesChain as $key => $category) {
                $temp = $this->findInBranch($category['waregroup'], $branch);
                if ($temp) {
                    $branch = $temp;
                    $parentId = $temp['id'];
                } else {
                    $parentId = $this->createBranch(array_slice($categoriesChain, $key), $parentId);
                    $this->loadCategoryTree();
                    break;
                }
            }

            return $parentId;
        } else {
            $branch = $this->findCategoryBranchByParam($categoriesChain[0]['waregroup'], $this->categoryTree, 'name', false);
            if (!$branch) {
                $parentId = $this->createBranch($categoriesChain, null);
                $this->loadCategoryTree();

                return $parentId;
            }

            $parentId = $branch['id'];
            foreach ($categoriesChain as $key => $category) {
                if ($key == 0) {
                    continue;
                }
                $temp = $this->findInBranch($category['waregroup'], $branch);
                if ($temp) {
                    $branch = $temp;
                    $parentId = $temp['id'];
                } else {
                    $parentId = $this->createBranch(array_slice($categoriesChain, $key), $parentId);
                    $this->loadCategoryTree();
                    break;
                }
            }

            return $parentId;
        }
    }

    public function getDefaultCmsListingPageId(): string
    {
        if (null !== $this->defaultCmsListingPageId) {
            return $this->defaultCmsListingPageId;
        }
        /*
        $result = $this->connection->fetchColumn('
                SELECT id
                FROM cms_page
                WHERE locked = :locked
                AND type = :type
            ',['locked' => '1','type' => 'product_list']
        );
*/

        $result = $this->connection->executeQuery('
                SELECT id
                FROM cms_page
                WHERE locked = 1
                AND type = "product_list"
            ')->fetchOne();

        if ($result === false) {
            throw new \RuntimeException('Default Cms Listing page not found');
        }

        $this->defaultCmsListingPageId = Uuid::fromBytesToHex((string)$result);

        return $this->defaultCmsListingPageId;
    }



    protected function loadManufacturers(): void
    {
        $manufacturers = $this->productManufacturerRepository->search(new Criteria(), $this->context)->getEntities();
        $ret = [];
        foreach ($manufacturers as $manufacturer) {
            $ret[$manufacturer->getName()] = $manufacturer->getId();
        }
        $this->manufacturers = $ret;
    }

    public function getManufacturerId(string $manufacturerName): string
    {
        if ($this->manufacturers === null) {
            $this->loadManufacturers();
        }

        if (isset($this->manufacturers[$manufacturerName])) {
            $manufacturerId = $this->manufacturers[$manufacturerName];
        } else {
            $manufacturerId = Uuid::randomHex();
            $this->productManufacturerRepository->create([
                [
                    'id'   => $manufacturerId,
                    'name' => [
                        $this->systemDefaultLocaleCode => $manufacturerName,
                    ],
                ],
            ], $this->context);
            $this->manufacturers[$manufacturerName] = $manufacturerId;
        }

        return $manufacturerId;
    }

//    /**
//     * Returns product ids which are compatible with same devices
//     * [['a_id'=>hexid, 'a_version_id'=>hexversionid], ...].
//     * 06/2025 unused
//     */
//    public function getAlternateProductIds(ProductEntity $product): array
//    {
//        $result = $this->connection->executeQuery('
//SELECT DISTINCT LOWER(HEX(a.id)) a_id, LOWER(HEX(a.version_id)) a_version_id
// FROM product a,
//      topdata_device_to_product tdp,
//      topdata_device_to_product tda
// WHERE (0x' . $product->getId() . ' = tdp.product_id) AND (0x' . $product->getVersionId() . ' = tdp.product_version_id)
//   AND  (a.id = tda.product_id) AND (a.version_id = tda.product_version_id)
//   AND  (tdp.device_id = tda.device_id)
//   AND  (0x' . $product->getId() . ' != a.id)
//            ')->fetchAllAssociative();
//
//        return $result;
//
//        /*
//         #all alternates:
//         SELECT DISTINCT p.product_number, a.product_number
// FROM product p,
//      product a,
//      topdata_device_to_product tdp,
//      topdata_device_to_product tda
// WHERE (p.id = tdp.product_id) AND (p.version_id = tdp.product_version_id)
//   AND  (a.id = tda.product_id) AND (a.version_id = tda.product_version_id)
//   AND  (tdp.device_id = tda.device_id)
//   AND  (p.id != a.id)
//         */
//    }


//    public function getPropertyGroupsOptionsArray(): array
//    {
//        if (is_array($this->propertyGroupsOptionsArray)) {
//            return $this->propertyGroupsOptionsArray;
//        }
//
//        $this->propertyGroupsOptionsArray = [];
//
//        // --- THE FIX ---
//        // Step 1: Reliably get the binary ID of the system's default language.
//        // This ensures we are reading the same language that we are writing.
//        $systemLangIdBytes = $this->connection->fetchOne(
//            'SELECT language.id
//             FROM language
//             JOIN locale ON language.translation_code_id = locale.id
//             WHERE locale.code = :code',
//            ['code' => $this->systemDefaultLocaleCode]
//        );
//
//        if ($systemLangIdBytes === false) {
//            // Fallback or throw an error if the system language can't be found.
//            // This protects against a misconfigured system.
//            throw new \RuntimeException(sprintf(
//                'System default language with locale code "%s" could not be found in the database.',
//                $this->systemDefaultLocaleCode
//            ));
//        }
//
//        // Step 2: Convert the binary ID to its hex representation for the SQL query.
//        $langIdHex = bin2hex($systemLangIdBytes);
//        // --- END FIX ---
//
//
//        // Step 3: Use the dynamically determined language ID in the query.
//        $result = $this->connection->executeQuery("
//            SELECT LOWER(HEX(pg.id)) pg_id, pgt.name pg_name, LOWER(HEX(pgo.id)) pgo_id, pgot.name pgo_name
//            FROM property_group_option as pgo,
//                 property_group_option_translation as pgot,
//                 property_group as pg,
//                 property_group_translation as pgt
//            WHERE (pg.id = pgo.property_group_id)
//                AND (pg.id = pgt.property_group_id)
//                AND (pgt.language_id = 0x$langIdHex) -- <-- Using the correct language ID
//                AND (pgo.id = pgot.property_group_option_id)
//                AND (pgot.language_id = 0x$langIdHex) -- <-- Using the correct language ID
//        ")->fetchAllAssociative();
//
//        foreach ($result as $res) {
//            if (!isset($this->propertyGroupsOptionsArray[$res['pg_id']])) {
//                $this->propertyGroupsOptionsArray[$res['pg_id']] = [
//                    'name'    => $res['pg_name'],
//                    'options' => [],
//                ];
//            }
//            $this->propertyGroupsOptionsArray[$res['pg_id']]['options'][$res['pgo_id']] = $res['pgo_name'];
//        }
//
//        return $this->propertyGroupsOptionsArray;
//    }



//    /**
//     * 06/2025 unused
//     */
//    public function productAlternatesCount(string $productId)
//    {
//        if (!UtilUuid::isValidUuid($productId)) {
//            return 0;
//        }
//        /*
//        return $this->connection->executeQuery('
//SELECT COUNT(*) as cnt
// FROM topdata_product_to_alternate
// WHERE 0x'.$productId.' = product_id
//     LIMIT 1
//            ')->fetchColumn();
//*/
//
//        return $this->connection->executeQuery('SELECT COUNT(*) as cnt FROM topdata_product_to_alternate WHERE 0x' . $productId . ' = product_id LIMIT 1')->fetchOne();
//    }

//    public function getDeviceSynonymsIds(string $deviceId): array
//    {
//        $xids = [];
//
//        if (!UtilUuid::isValidUuid($deviceId)) {
//            return $xids;
//        }
//
//        $deviceIds = $this->connection->executeQuery('
//SELECT LOWER(HEX(synonym_id)) as id
// FROM topdata_device_to_synonym
// WHERE 0x' . $deviceId . ' = device_id
//            ')->fetchAllAssociative();
//        foreach ($deviceIds as $id) {
//            $xids[] = $id['id'];
//        }
//
//        return $xids;
//    }
}
