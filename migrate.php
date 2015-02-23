#!/usr/bin/php
<?php
/* edetalj standard 
define('UC_PUBLIC_KEY', '169bf4c6eccf97126d89');
define('UC_SECRET_KEY', '853b1e006d74650b27a7');
 */
/* jsenergi */
define('UC_PUBLIC_KEY', 'ef025c9716b466c5e63f');
define('UC_SECRET_KEY', '5e50c4d4af5d8b421c05');


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
foreach($categories as $category) 
{
    $cat = new Category();
    $cat->setName($category['name']);
    $cat->setUrl(url_string($category['id']));
    $map_categories[$category['id']] = $cat;

    /* Save category to mongodb */
    $dm->persist($cat);

}
putline("OK\n");

$dm->flush();
$dm->clear();

/* Setup category hieararchy */

putline("Setting up category relations... ");
foreach($categories as $old_cat) 
{
    $parent_id = $old_cat['parent'];
    if (!empty($parent_id)) {
        $cat = $map_categories[$old_cat['id']];
        $parent = $map_categories[$parent_id];
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

$i = 0;
putline("Creating products... ");
foreach($products as $product) 
{
    /* Validation */

    /* Create product */
    $prod = new Product();
    $prod->setTitle($product['title']);
    $prod->setActive(true);
    $prod->setDescription($product['description']);
    $prod->setReference($product['reference']);
    $prod->setUrl(url_string($product['category'] . '-' . $product['title']));
    $prod->setPrice(new Money(100 * intval($product['price'])));
    $map_products[$product['id']] = $prod;

    /* Save to mongodb */
    $dm->persist($prod);

    /* Create cms page */
    $page = new Page('/product/' . $prod->getUrl());
    $page->setTitle($product['title']);
    $page->setDescription($product['description']);
    $page->setView('webshop\\views\\ProductView');
    $page->setTemplate(ProductView::TEMPLATE);
    $page->setEntryPoint(ProductView::ENTRY);

    /* Save page to mongodb */
    $dm->persist($page);

    $i++;

    if ($i % 100 == 0) {
        echo "$i\n";
        $dm->flush();
        $dm->clear();
    }
}

$dm->flush();

putline("\n");
putline("Done. $i products created\n");

$i = 0;
putline("Adding products to categories... ");
foreach($products as $product) 
{
    $prod = $map_products[$product['id']];
    $category = $map_categories[$product['category']];
    if ($category == null)
        continue;

    $category->addProduct($prod);

    $i++;
    if ($i % 200 == 0) {
        echo "$i\n";
        $dm->flush();
        $dm->clear();
    }
}
putline("OK.\n");

$dm->flush();
$dm->clear();

if ($upload_images) {
    putline("Uploading product images...\n");
    /* Sort images */
    $i = 0;
    foreach($products as $product) 
    {
        if (!isset($product['image']) || strlen($product['image']) <= 0)
            continue;

        $prod = $map_products[$product['id']];

        $image = $product['image'];
        if (!file_exists($image)) {
            echo "could not find $image (product: {$product['title']}\n";
            continue;
        }

        $img = uploadImage($image);
        $prod->addImage($img);

        putline("$image --> " . $img->getUUID() . "\n");
        $i++;
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

function uploadImage($path) 
{
    global $uc_api;

    $file = $uc_api->uploader->fromPath($path);
    $file->store();

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
