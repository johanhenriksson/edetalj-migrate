#!/usr/bin/php
<?php
/* edetalj standard 
define('UC_PUBLIC_KEY', '169bf4c6eccf97126d89');
define('UC_SECRET_KEY', '853b1e006d74650b27a7');
 */
/* jsenergi */
define('UC_PUBLIC_KEY', '5ebc3be286fab04c6676');
define('UC_SECRET_KEY', '6c543d743eeb3a76cec8');


require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/cli.php';
require __DIR__ . '/../edetalj/webshop/util/Constants.php';
require __DIR__ . '/../edetalj/webshop/util/Functions.php';
require __DIR__ . '/../edetalj/webshop/util/Exceptions.php';

/* V2 Data structures */
use webshop\Page;
use webshop\Money;
use webshop\Product;
use webshop\Category;
use webshop\Attribute;
use webshop\AttributeValue;
use webshop\cms\ProductImage;
use webshop\views\ProductView;

use Doctrine\MongoDB\Connection;
use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\Driver\YamlDriver;

use Uploadcare;

/*
 * Version 1.0 -> 2.0 Database Migration tool
 * Johan Henriksson, Nov 2014
 *
 * TODO:
 *  - Add pages for products & categories with their metadata information
 */

putline("**********************\n");
putline("Edetalj migration tool\n");
putline("**********************\n");
putline("\n");

chmod(__DIR__ . '/images', 0777);

require 'import.php';

putline("OK.\n");

/* Connect to MongoDB */

putline("Target database: "); 
$dbname = getline();

$config = new Configuration();
$config->setProxyNamespace('Proxies');
$config->setHydratorNamespace('Hydrators');
$config->setProxyDir(__DIR__ . '/../edetalj/cache/proxies');
$config->setHydratorDir(__DIR__ . '/../edetalj/cache/hydrators');
$config->setMetadataDriverImpl(new YamlDriver(__DIR__ . '/../edetalj/metadata'));
$config->setDefaultDB($dbname);

$dm = DocumentManager::create(new Connection(), $config);

$page_repo = $dm->getDocumentCollection('\webshop\Page');
$prod_repo = $dm->getDocumentCollection('\webshop\Product');
$cat_repo  = $dm->getDocumentCollection('\webshop\Category');

/* TODO: Counters */

/* Uploadcare API */

$uc_api = new Uploadcare\Api(UC_PUBLIC_KEY, UC_SECRET_KEY);
$upload_images = false;

putline("Upload images to uploadcare? (y/N) ");
$upl_input = getline();
if ($upl_input == "y" || $upl_input == "Y") 
    $upload_images = true;

/* All done. */

putline("\n");
putline("Ready. Press enter to migrate.\n");
putline("NOTICE: All categories and products in '$dbname' will be deleted.\n");
getline();

/* Clear database */

$page_repo->remove(array());
$prod_repo->remove(array());
$cat_repo->remove(array());
$dm->flush();
putline("Target collections cleared.\n");

/* Create categories */
$map_categories = array();

putline("Creating categories... ");
$i = 1;
foreach($categories as $category) 
{
    $cat = new Category();
    $cat->setName($category['name']);
    $cat->setUrl(url_string($category['path']));
    $cat->setCategoryId($i);
    $map_categories[$category['path']] = $cat;

    /* create CMS page */
    $page = new Page('/category/' . $cat->getCategoryId() . '/' . $cat->getUrl());
    $page->setName($category['name']);
    $page->setView('webshop\\views\\CategoryView');
    $page->setTemplate(CategoryView::TEMPLATE);
    $page->setEntryPoint(CategoryView::ENTRY);

    /* load cms content */

    /* Save category to mongodb */
    $dm->persist($cat);
    $dm->persist($page);
    $i++;

}
putline("OK\n");
$category_counter = $i;

$dm->flush();
//$dm->clear();

/* Setup category hieararchy */

putline("Setting up category relations... ");
foreach($categories as $old_cat) 
{
    $parent_path = $old_cat['parent'];
    if (!empty($parent_path)) {
        $cat    = $map_categories[$old_cat['path']];
        $parent = $map_categories[$parent_path];
        $cat->setParent($parent->getID());
        $cat->parent_ref = $parent;

        $dm->persist($cat);
    }
}
putline("OK.\n");

$dm->flush();
$dm->clear();

/* Create products */
$map_products = array();

$i = 1;
putline("Creating products... ");
$last_category = null;
$just_cleared = false;
foreach($products as $product) 
{
    /* Validation */

    if (isset($map_products[$product['path']])) {
        putline('ERR Duplicate Product in map: ' . $product['path'] . "\n");
        continue;
    }

    /* Create product */
    $prod = new Product();
    $prod->setTitle($product['title']);
    $prod->setActive(true);
    $prod->setDescription($product['description']);
    $prod->setReference($product['reference']);
    $prod->setUrl(url_string($product['category'] . '-' . $product['title']));
    $prod->setPrice(new Money(100 * intval($product['price'])));
    $prod->setProductId($i);

    $map_products[$product['path']] = $prod;


    /* Create cms page */
    $page = new Page('/product/' . $prod->getProductId() . '/' . $prod->getUrl());
    $page->setTitle($product['title']);
    $page->setDescription($product['description']);
    $page->setView('webshop\\views\\ProductView');
    $page->setTemplate(ProductView::TEMPLATE);
    $page->setEntryPoint(ProductView::ENTRY);

    /* add product to category */
    $category = $map_categories[$product['category']];
    if ($category == null) {
        putline('ERR: Could not find category ' . $product['category'] . "\n");
        continue;
    }

    $just_cleared = false;
    if ($last_category != null && $category != $last_category) {
        echo "New category. Clearing cache\n";
        $dm->clear();
        $just_cleared = true;
    }
    $last_category = $category;

    echo "$i " . $product['path'] . "...";

    /* Save to mongodb */
    $dm->persist($prod);
    $dm->persist($page);
    $dm->flush();

    $category->addProduct($prod);
    $dm->persist($category);
    $dm->flush();


    /* Save page to mongodb */

    $i++;
    echo "OK\n";

    if ($i % 100 == 0) {
        //$dm->clear();
    }
}
$product_counter = $i;

$dm->clear();

putline("\n");
putline("Done. $i products created\n");

if ($upload_images) 
{
    putline("Uploading product images...\n");
    /* Sort images */
    $i     = 0;
    $total = count($products);

    foreach($products as $product) 
    {
        $i++;
        if (!isset($product['image']) || strlen($product['image']) <= 0)
            continue;

        $prod = $map_products[$product['path']];
        if ($prod == null)
            continue;

        $image = $product['image'];
        if (!file_exists($image)) {
            echo "could not find $image (product: {$product['title']}\n";
            continue;
        }

        $img = uploadImage($image);
        $prod->addImage($img);
        $dm->persist($prod);

        putline("$i/$total: $image --> " . $img->getUUID() . "\n");

        if ($i % 20 == 0) {
            $dm->flush();
            $dm->clear();
        }
    }

    putline("Uploaded $i product images\n");
}

/* Flushing changes to mongodb */
$dm->flush();
$dm->clear();

putline("\n");
putline("All done.\n");

/******************************************************
    Functions
 ******************************************************/

/** keep track of uploaded files to avoid re-uploading
 *  several ProductImage instances could use the same file ID */
$uc_cache = array();

function uploadImage($path) 
{
    global $uc_api;
    global $uc_cache;

    $file = null;
    if (array_key_exists($path, $uc_cache)) {
        /* cache hit - reuse file id */
        $file = $uc_cache[$path];
    }
    else {
        /* new file - upload it */
        $file = $uc_api->uploader->fromPath($path);
        $file->store();
        $uc_cache[$path] = $file;
    }

    /* create new product image object */
    $image = new ProductImage($file->getFileId());
    return $image;
}

function getProductImages($id)
{
    global $product_images;

    $images = array();
    foreach($product_images as $image) {
        if ($image['product'] != $id)
            continue;
        $images[] = $image['url'];
    }

    return $images;
}
