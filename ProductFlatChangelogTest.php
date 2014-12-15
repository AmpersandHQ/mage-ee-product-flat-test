<?php
require_once __DIR__ . '/../../../app/Mage.php';

class ProductFlatChangelogTest extends PHPUnit_Framework_TestCase
{
    public function testMoreThan500Changes()
    {
        // ensure a full re-index does not run for the duration of this test
        if (!$this->acquireReindexLock()) {
            throw new Exception('Full reindex process is already running.');
        }

        $description = 'Mage EE product flat index bug test script created this product: ' . now();
        
        try {
            $this->disableLiveReindexObserver();
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
    
    private function disableLiveReindexObserver()
    {
        Mage::app()->getConfig()
            ->getNode('global/events/catalog_product_save_commit_after/observers/enterprise_product_flat')
                ->addChild('type', 'disabled');
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
                    'description' => $description,
                )
            );
        }
        
        return $createdProductIds;
    }
    
    private function processChangelog()
    {
        Mage::getModel('enterprise_mview/client')
            ->init('catalog_product_flat')
            ->execute();
    }
    
    private function validateFlatTable(array $productIds, $description)
    {
        $table = new Zend_Db_Table(array(
            'adapter' => Mage::getSingleton('core/resource')->getConnection(Mage_Core_Model_Resource::DEFAULT_READ_RESOURCE),
            'name' => 'catalog_product_flat',
        ));
        
        foreach ($productIds as $productId) {
            $row = $table->fetchRow(array('entity_id = ?', $productId));
            $this->assertNotNull($row);
            $this->assertEquals($description, $row->description);
        }
    }
}