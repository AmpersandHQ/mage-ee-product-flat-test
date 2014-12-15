<?php
class ProductFlatChangelogTest extends PHPUnit_Framework_TestCase
{
    public function testMoreThan500Changes()
    {
        Mage::app();
        
        // ensure a full re-index does not run for the duration of this test
        if (!$this->acquireReindexLock()) {
            throw new Exception('Full reindex process is already running.');
        }

        $description = 'Mage EE product flat index bug test script created this product: ' . now();
        
        try {
            $this->enableFlatTable();
            $this->disableLiveReindex();
            $productIds = $this->createProducts($description, 550);
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
        $indexerData = Mage::getConfig()->getNode(
            Enterprise_Index_Helper_Data::XML_PATH_INDEXER_DATA . '/catalog_product_flat'
        );
        
        Mage::getModel('enterprise_mview/client')
            ->init((string)$indexerData->index_table)
            ->execute((string)$indexerData->action_model->changelog);
    }
    
    private function validateFlatTable(array $productIds, $description)
    {
        $table = new Zend_Db_Table(array(
            'db' => Mage::getSingleton('core/resource')->getConnection(Mage_Core_Model_Resource::DEFAULT_READ_RESOURCE),
            'name' => 'catalog_product_flat_1',
        ));
        
        foreach ($productIds as $productId) {
            $row = $table->fetchRow(array('entity_id = ?' => $productId));
            $this->assertNotNull($row);
            $this->assertEquals($description, $row->short_description);
        }
    }
}
