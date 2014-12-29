# Magento EE Product Flat Index Bug #

Magento applications maintain a number of tables in the database called *indexes* which are designed to optimise front-end performance. These index tables essentially provide a simplified view of EAV data held elsewhere in the database.

Magento Enterprise Edition maintains a number *changelog* tables. Every time a product or category is created, updated or deleted the ID of the product or category will be written to the changelog tables using MySQL triggers.

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

## The Problem ##

When more than 500 products are changed between two executions of the indexer, the changes made to all but the first 500 products will be disregarded by Magento.

\* Technically, only a subset of product attributes appear in the flat tables.
