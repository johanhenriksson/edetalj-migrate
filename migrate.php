#!/usr/bin/php
<?php
define('UC_PUBLIC_KEY', '169bf4c6eccf97126d89');
define('UC_SECRET_KEY', '853b1e006d74650b27a7');

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

putline("Dump file name: ");

$file = getline();
$end = substr($file, -4);
if ($end != '.php')
    $file .= '.php';

chmod(__DIR__ . '/images', 0777);

putline("Loading from: $file... ");

require $file;

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
$catmap = array();

putline("Creating categories... ");
foreach($categories as $category) 
{
    $cat = new Category();
    $cat->setName($category['name']);
    $cat->setUrl($category['urlstring']);
    $map_categories[$category['id']] = $cat;

    /* Save category to mongodb */
    $dm->persist($cat);



    putline($category['name'] . ", ");
}
putline("\n\n");

$dm->flush();

/* Setup category hieararchy */

putline("Setting up category relations... ");
foreach($categories as $old_cat) 
{
    $parent_id = $old_cat['parent'];
    if ($parent_id != 0) {
        $cat = $map_categories[$old_cat['id']];
        $parent = $map_categories[$parent_id];
        $cat->setParent($parent->getID());
        $cat->parent_ref = $parent;

        $dm->persist($cat);
    }
}
putline("OK.\n");

$dm->flush();

/* Create products */
$map_products = array();

$i = 0;
putline("Creating products... ");
foreach($products as $product) 
{
    /* Validation */
    if ($product['category'] == 0)
        continue;

    /* Create product */
    $prod = new Product();
    $prod->setTitle($product['title']);
    $prod->setDescription($product['description']);
    $prod->setReference($product['reference']);
    $prod->setUrl($product['urlstring']);
    $prod->setWeight($product['weight']);
    $prod->setPrice(new Money(100 * intval($product['price'])));
    $map_products[$product['id']] = $prod;

    /* Add attributes */
    $attrs = getProductAttributes($product['id']);
    foreach($attrs as $attr)
        $prod->addAttribute($attr);

    /* Save to mongodb */
    $dm->persist($prod);

    /* Create cms page */
    $page = new Page('/product/' . $prod->getUrl());
    $page->setTitle($product['pagetitle']);
    $page->setDescription($product['pagedesc']);
    $page->setView('webshop\\views\\ProductView');
    $page->setTemplate(ProductView::TEMPLATE);
    $page->setEntryPoint(ProductView::ENTRY);

    /* Save page to mongodb */
    $dm->persist($page);

    putline($product['title'] . ", ");
    $i++;
}
putline("\n");
putline("Done. $i products created\n");

putline("Adding products to categories... ");
foreach($products as $product) 
{
    if ($product['category'] == 0)
        continue;

    $prod = $map_products[$product['id']];
    $category = $map_categories[$product['category']];
    if ($category != null)
        $category->addProduct($prod);
}
putline("OK.\n");

putline("Uploading product images...\n");
/* Sort images */
$i = 0;
foreach($products as $product) 
{
    /* Skip out-of-category products */
    if ($product['category'] == 0)
        continue;

    $prod = $map_products[$product['id']];
    $images = getProductImages($product['id']);

    /* Category path */
    $cat_path = "/";
    $cat = $map_categories[$product['category']];
    while($cat != null) {
        $cat_path = "/" . $cat->getName() . $cat_path;;
        if (isset($cat->parent_ref))
            $cat = $cat->parent_ref;
        else
            $cat = null;
    }

    /* Product Name */
    $img_path = __DIR__ . '/images/' . $dbname . $cat_path . $prod->getTitle() . '/';
    if (!file_exists($img_path))
        mkdir($img_path, 0777, true); 

    /* Copy images */
    foreach($images as $image) {
        $name = pathinfo($image)['basename'];

        $old_path = __DIR__ . '/images/' . $image;

        if (file_exists($old_path)) 
        {
            $new_path = $img_path . $name;

            $img = uploadImage($old_path);
            $prod->addImage($img);

            putline("$name --> " . $img->getUUID() . "\n");
            $i++;
        }
    }
}

putline("Uploaded $i product images\n");

/* Flushing changes to mongodb */
$dm->flush();

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

function getProductAttributes($product_id)
{
    global $product_attr;

    $attr_ids = array();
    foreach($product_attr as $pa) {
        if ($pa['product'] == $product_id)
            $attr_ids[] = $pa['attribute'];
    }

    $attributes = array();
    foreach($attr_ids as $attr_id)
        $attributes[] = getAttribute($attr_id);
    return $attributes;
}

function findAttribute($id) 
{
    global $attribute_names;
    foreach($attribute_names as $attr) {
        if ($attr['id'] == $id)
            return $attr;
    }
    return null;
}

function getAttribute($id) 
{
    $attr = findAttribute($id);
    if ($attr === null)
        throw new Exception("No such attribtue: $id");

    $attribute = new Attribute();
    $attribute->setName($attr['name']);

    $values = getAttributeValues($id);
    foreach($values as $value) {
        $attribute->addValue($value);
    }

    return $attribute;
}

function getAttributeValues($attr_id)
{
    global $attributes;

    $vals = array();
    foreach($attributes as $attr_val) 
    {
        if ($attr_val['type'] != $attr_id) 
            continue;

        /* Skip empty options */
        if (empty(trim($attr_val['value'])))
            continue;

        $obj = new AttributeValue();
        $obj->setValue($attr_val['value']);
        $obj->setPrice(new Money(100 * intval($attr_val['price'])));
        $vals[] = $obj;
    }

    return $vals;
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
