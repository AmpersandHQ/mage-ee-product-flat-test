<?php
class ProductFlatChangelogTest extends PHPUnit_Framework_TestCase
{
    const XML_PATH_FLAT_TABLE_ENABLED = 'default/catalog/frontend/flat_catalog_product';
    const XML_PATH_LIVE_REINDEX_ENABLED = Enterprise_Catalog_Model_Index_Observer_Flat::XML_PATH_LIVE_PRODUCT_REINDEX_ENABLED;
    const LOCK_NAME = Enterprise_Index_Model_Observer::REINDEX_FULL_LOCK;
    
    private $client;
    private $changelogModel;
    private $indexTable;
    private $changelogTable;
    
    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        
        Mage::app();
        
        $indexerData = Mage::getConfig()->getNode(
            Enterprise_Index_Helper_Data::XML_PATH_INDEXER_DATA . '/catalog_product_flat'
        );
        
        $this->client = Mage::getModel('enterprise_mview/client')
            ->init((string)$indexerData->index_table);
        
        $this->changelogModel = (string)$indexerData->action_model->changelog;
        
        $connection = Mage::getSingleton('core/resource')
            ->getConnection(Mage_Core_Model_Resource::DEFAULT_READ_RESOURCE);
        
        $this->indexTable = new Zend_Db_Table(array(
            'db' => $connection,
            'name' => Mage::helper('enterprise_catalog/product')->getFlatTableName(1),
        ));
        
        $this->changelogTable = new Zend_Db_Table(array(
            'db' => $connection,
            'name' => $this->client->getMetadata()->getChangelogName(),
        ));
    }
    
    public function testMoreThan500Changes()
    {
        // ensure a full re-index does not run for the duration of this test
        if (!Enterprise_Index_Model_Lock::getInstance()->setLock(self::LOCK_NAME)) {
            throw new Exception('Full reindex process is already running.');
        }

        $description = 'Mage EE product flat index bug test script created this product: ' . now();
        
        try {
            // force enable the flat table for this process
            Mage::getConfig()->setNode(self::XML_PATH_FLAT_TABLE_ENABLED, 1);
            // force disable live reindex for this process
            Mage::app()->getStore()->setConfig(self::XML_PATH_LIVE_REINDEX_ENABLED, 0);
            $productIds = $this->createProducts($description, 550);
            $this->processChangelog();
            $this->validateFlatTable($productIds, $description);
        } catch (Exception $e) {
            Enterprise_Index_Model_Lock::getInstance()->releaseLock(self::LOCK_NAME);
            throw $e;
        }

        Enterprise_Index_Model_Lock::getInstance()->releaseLock(self::LOCK_NAME);
    }
    
    private function createProducts($description, $limit)
    {
        $createdProductIds = array();

        $productApi = Mage::getSingleton('catalog/product_api');
        $attributeSetId = Mage::getSingleton('catalog/product')->getDefaultAttributeSetId();
        for ($i = 0; $i < $limit; $i++) {
            $sku = 'EE-INDEX-BUG-' . str_pad(mt_rand(1, 1000000), 7, 0);

            $createdProductIds[] = $productApi->create(
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
        $metadata = $this->client->getMetadata();
        
        do {
            $this->client->execute($this->changelogModel);
            $metadata->load($metadata->getId());
        } while ($metadata->getVersionId() < $this->getMaxVersionId());
    }
    
    private function validateFlatTable(array $productIds, $description)
    {
        foreach ($productIds as $productId) {
            $row = $this->indexTable->fetchRow(array('entity_id = ?' => $productId));
            $this->assertNotNull($row);
            $this->assertEquals($description, $row->short_description);
        }
    }
    
    private function getMaxVersionId()
    {
        return $this->changelogTable->select(Zend_Db_Table::SELECT_WITH_FROM_PART)
            ->columns(array('max_version_id' => 'MAX(version_id)'))
            ->query()
                ->fetchObject();
    }
}
