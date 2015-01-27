# Magento EE Product Flat Index Bug #

Magento applications maintain a number of tables in the database called *indexes* which are designed to optimise front-end performance. These index tables essentially provide a simplified view of data held elsewhere in the database.

Magento Enterprise Edition maintains a number of *changelog* tables. Every time a product or category is created, updated or deleted the ID of the product or category will be written to the changelog tables using MySQL triggers.

Up to once per minute, via cron, Magento will read any new ID's from the changelog tables and re-generate any related rows in the index tables, ensuring that the latest data is visible on the front-end of the website.

## Product Flat Index ##

Magento uses an EAV pattern to represent product data, which means the data is held in many separate database tables. EAV provides merchants with flexibility, as they can easily define their own product attributes and customise existing ones, but it also hinders performance as working with so many tables is complex.

To improve front-end performance, Magento has an index for product data called the product flat index. The index comprises a single table per store, with each table containing a single row for each enabled product and a column for each product attribute*.

Every time a product is created or deleted, or one of its attribute values changes, one or more rows is added to the product flat changelog containing the ID of the product which has changed. Up to once per minute, that table is read by Magento, and any changes which have not already been processed are passed to the product flat changelog indexer, which updates the product flat index for the affected products.

The following scenario should make the process a bit clearer:
```
12:00:00 John executes a product import containing 10 products.
12:00:30 John visits the front-end of the website and finds that his changes have not taken effect.
12:01:00 Magento reads the changelog and re-builds the index rows for those 10 products.
12:01:30 John visits the front-end again and finds that his changes have now taken effect.
```

\* Technically, only a subset of product attributes appear in the flat tables.

## The Problem ##

When there are more than 500 distinct unprocessed product ID's in the product flat changelog, the product flat indexer will only process 500 of those products, but will mark all of them as having been processed. As a result, product data will display incorrectly on the front-end indefinitely.

The bug only affects the product flat index, and can be traced to a single method:
```php
Enterprise_Catalog_Model_Index_Action_Product_Flat_Refresh

protected function _reindex($storeId, array $changedIds = array())
{
    ...
    if (!self::$_calls) { // code within this block only executes once per PHP process
        $this->_fillTemporaryEntityTable($entityTableName, $entityTableColumns, $changedIds);
        ...
        $this->_fillTemporaryTable($tableName, $columns, $changedIds);
    }
    ...
    self::$_calls++;
    ...
}
```
Looking at the snippet of code above, it is apparent that the code block within the `if (!self::$_calls)` statement will only ever be executed once within the context of a PHP process, regardless of the number of calls to `_reindex()`. However, `$changedIds` is provided as an argument to the method and is used within the run-once code block to 'fill temporary entity table' and 'fill temporary table'. As such, those temporary tables are filled using the `$changedIds` provided in the first invokation of `_reindex()` only, so all but the first invokation of `_reindex()` will fail to behave as expected.

When processing the flat product index, Magento Enterprise splits the backlog of product ID's into batches of 500 products and processes each batch in sequence within a single PHP process, as described below:

```php
Enterprise_Catalog_Model_Index_Action_Product_Flat_Refresh_Changelog
  extends Enterprise_Catalog_Model_Index_Action_Product_Flat_Refresh

public function execute()
{
  ...
  $idsBatches = array_chunk($changedIds, Mage::helper('enterprise_index')->getBatchSize());
  
  foreach ($idsBatches as $ids) {
      $this->_reindex($store->getId(), $ids);
  }
  ...
}
```

It is evident from the above code snippet that Magento Enterprise intends to call the broken ``_reindex()`` method multiple times with different product ID's within the context of a single PHP process, which will not work.

## The Solution ##

The easiest solution is to remove the static condition from ``_reindex()``. Magento were obviously hoping to improve performance with the run-once-per-process condition, but in practice doing so shaves a few milliseconds off a process which usually takes several seconds to run. The most simple solution would be to remove the static condition so that the code block within it runs on every method invokation.

## Proving the Bug ##

In order to prove this bug and any fix which is produced, this repository includes a PHPUnit test case which can be run against vanilla Magento Enterprise Edition installations. This test case has been run against Magento Enterprise Edition >=1.13.0.0,<=1.14.1.0 and the bug exists on all of those versions. Note that 1.14.1.0 is the latest version of Magento Enterprise Edition at the time of writing.

Note that this test case saves over 500 products to the MySQL database of the Magento instance and can take several minutes to complete. It should not be executed on a production instance of Magento.

### Downloading the PHPUnit Test ###

Navigate to the root of your Magento installation and download the test case using Composer:

```
composer require ampersand/mage-ee-product-flat-test
```

### Executing the PHPUnit Test ###

```
cd vendor/ampersand/mage-ee-product-flat-test
../../bin/phpunit
```

The test case creates 550 products before running the product flat indexing process. For each product, two assertions are made to check whether the flat table is correct: first, it is asserted that the product is present in the flat table, and second, it is asserted that the row in the flat table contains the correct data.

When executing the test case against a vanilla installation of Magento Enterprise Edition you should see a failure message similar to the following:

```
Time: 3.02 minutes, Memory: 47.50Mb

There was 1 failure:

1) ProductFlatChangelogTest::testMoreThan500Changes
Product with SKU EE-INDEX-BUG-4212650 not found in flat table.
Failed asserting that null is not null.

/ampersand/builds/ee-1.13.1.0/vendor/ampersand/mage-ee-product-flat-test/ProductFlatChangelogTest.php:133
/ampersand/builds/ee-1.13.1.0/vendor/ampersand/mage-ee-product-flat-test/ProductFlatChangelogTest.php:53
                                        
FAILURES!                               
Tests: 1, Assertions: 1001, Failures: 1.
```

The above output tells us that the 1001st assertion failed, which implies that the first 1000 assertions succeeded. Given that there are two assertions made per product saved, those 1000 successful assertions represent 500 valid products, with the 501st product being invalid. This output, which tells us that the index process is not working as it should, is the expected output when executing this test case against a vanilla installation of Magento Enterprise Edition.

After removing the static condition from the broken `_reindex()` method as described earlier, the test case should run successfully and produce an output similar to the following:

```
Time: 3.03 minutes, Memory: 47.50Mb

OK (1 test, 1100 assertions)
```
