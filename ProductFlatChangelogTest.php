<?php
/**
 * There is a bug affecting Magento Enterprise's product flat index. Specifically, when the
 * changelog index action attempts to process more than 500 products in one go, only the first 500
 * products will actually be processed correctly while Magento will effectively skip the remainder
 * of the products. Despite skipping some products, Magento will mark the changelog as being fully
 * processed, meaning some products will indefinitely remain out of date in the product flat tables.
 *
 * This test script can be run against a Magento Enterprise installation and proves this bug. This
 * script should not be run on a production environment.
 *
 * @author Josh Di Fabio <jd@amp.co>
 */
class ProductFlatChangelogTest extends PHPUnit_Framework_TestCase
{
    private $indexTable;
    private $changelogTable;

    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        Mage::app();

        $connection = Mage::getSingleton('core/resource')
            ->getConnection(Mage_Core_Model_Resource::DEFAULT_READ_RESOURCE);

        $this->indexTable = new Zend_Db_Table(array(
            'db' => $connection,
            'name' => Mage::helper('enterprise_catalog/product')->getFlatTableName(1),
        ));

        $this->changelogTable = new Zend_Db_Table(array(
            'db' => $connection,
            'name' => 'catalog_product_flat_cl',
        ));

        $this->productTable = new Zend_Db_Table(array(
            'db' => $connection,
            'name' => 'catalog_product_entity',
        ));
    }

    public function testMoreThan500Changes()
    {
        // ensure a full re-index does not run for the duration of this test
        if (!$this->acquireReindexLock()) {
            throw new Exception('Full reindex process is already running.');
        }

        $description = 'Mage EE product flat index bug test script created this product: ' . now();

        try {
            $this->enableFlatTable();
            $this->disableLiveReindex();

            $entityId = $this->getNewestProductId();
            $this->simulate500changes($entityId);
            $productIds = $this->createProducts($description, 1);

            $this->processChangelog();
            $this->validateFlatTable($productIds, $description);
        } catch (Exception $e) {
            $this->releaseReindexLock();
            throw $e;
        }

        $this->releaseReindexLock();
    }

    private function acquireReindexLock()
    {
        return Enterprise_Index_Model_Lock::getInstance()->setLock(Enterprise_Index_Model_Observer::REINDEX_FULL_LOCK);
    }

    private function releaseReindexLock()
    {
        Enterprise_Index_Model_Lock::getInstance()->releaseLock(Enterprise_Index_Model_Observer::REINDEX_FULL_LOCK);
    }

    private function enableFlatTable()
    {
        Mage::getConfig()->setNode('default/catalog/frontend/flat_catalog_product', 1);
    }

    private function disableLiveReindex()
    {
        Mage::app()->getStore()->setConfig(
            Enterprise_Catalog_Model_Index_Observer_Flat::XML_PATH_LIVE_PRODUCT_REINDEX_ENABLED,
            0
        );
    }

    /**
     * @author Joseph McDermott <code@josephmcdermott.co.uk>
     */
    private function getNewestProductId()
    {
        $productId = $this->productTable->select(Zend_Db_Table::SELECT_WITH_FROM_PART)
            ->columns(array('entity_id'))
            ->order('entity_id DESC')
            ->query()
            ->fetchColumn();

        return $productId;
    }

    /**
     * @author Joseph McDermott <code@josephmcdermott.co.uk>
     */
    private function simulate500changes($startEntityId)
    {
        // skip the ID that our new product will use
        $startEntityId += 2;

        for ($i = $startEntityId; $i < $startEntityId + 550; $i++) {
            $this->changelogTable->insert(array(
                'version_id' => NULL,
                'entity_id' => $i,
            ));
        }
    }

    private function createProducts($description, $limit)
    {
        $createdProductIds = array();

        $productResource = Mage::getResourceSingleton('catalog/product');
        $productApi = Mage::getSingleton('catalog/product_api');
        $attributeSetId = Mage::getSingleton('catalog/product')->getDefaultAttributeSetId();
        for ($i = 0; $i < $limit; $i++) {
            do {
                $sku = 'EE-INDEX-BUG-' . str_pad(mt_rand(1, 1000000), 7, 0);
            } while ($productResource->getIdBySku($sku));

            $createdProductIds[$sku] = $productApi->create(
                Mage_Catalog_Model_Product_Type::TYPE_SIMPLE,
                $attributeSetId,
                $sku,
                array(
                    'status' => Mage_Catalog_Model_Product_Status::STATUS_ENABLED,
                    'name' => $sku,
                    'short_description' => $description,
                )
            );
        }

        return $createdProductIds;
    }

    private function processChangelog()
    {
        $indexerData = Mage::getConfig()->getNode(
            Enterprise_Index_Helper_Data::XML_PATH_INDEXER_DATA . '/catalog_product_flat'
        );

        $client = Mage::getModel('enterprise_mview/client')
            ->init((string)$indexerData->index_table);

        $metadata = $client->getMetadata();

        do {
            $client->execute((string)$indexerData->action_model->changelog);
            $metadata->load($metadata->getId());
        } while ($metadata->getVersionId() < $this->getMaxVersionId());
    }

    private function validateFlatTable(array $productIds, $description)
    {
        foreach ($productIds as $sku => $productId) {
            $row = $this->indexTable->fetchRow(array('entity_id = ?' => $productId));
            $this->assertNotNull($row, sprintf('Product with SKU %s not found in flat table.', $sku));
            $this->assertEquals(
                $description,
                $row->short_description,
                sprintf('Product with SKU %s has wrong short description in flat table.', $sku)
            );
        }
    }

    private function getMaxVersionId()
    {
        $result = $this->changelogTable->select(Zend_Db_Table::SELECT_WITH_FROM_PART)
            ->columns(array('max_version_id' => 'MAX(version_id)'))
            ->query()
                ->fetchObject();

        return $result->max_version_id;
    }
}
