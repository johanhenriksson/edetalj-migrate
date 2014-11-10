#!/usr/bin/php
<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/cli.php';
require __DIR__ . '/../edetalj/webshop/util/Constants.php';

/* V2 Data structures */
use webshop\Money;
use webshop\Product;
use webshop\Category;
use webshop\Attribute;
use webshop\AttributeValue;

use Doctrine\MongoDB\Connection;
use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\Driver\YamlDriver;

putline("Edetalj Migration tool\n");
putline("Dump file name: ");

$file = getline();
$end = substr($file, -4);
if ($end != '.php')
    $file .= '.php';

chmod(__DIR__ . '/images', 0777);

putline("Loading from: $file\n");

require $file;

putline("Ok.\n");

/* Connect to MongoDB */

putline("Target database (will be cleared): ");
$dbname = getline();

$config = new Configuration();
$config->setProxyNamespace('Proxies');
$config->setHydratorNamespace('Hydrators');
$config->setProxyDir(__DIR__ . '/../edetalj/cache/proxies');
$config->setHydratorDir(__DIR__ . '/../edetalj/cache/hydrators');
$config->setMetadataDriverImpl(new YamlDriver(__DIR__ . '/../edetalj/metadata'));
$config->setDefaultDB($dbname);

$dm = DocumentManager::create(new Connection(), $config);

$prod_repo = $dm->getDocumentCollection('\webshop\Product');
$cat_repo  = $dm->getDocumentCollection('\webshop\Category');

/* All done. */

putline("Ready. Press enter to migrate.");
getline();

/* Clear database */

$prod_repo->remove(array());
$cat_repo->remove(array());
putline("Database cleared");

/* Create categories */
$catmap = array();

putline("Creating categories... ");
foreach($categories as $category) 
{
    putline($category['name'] . " ");
    $cat = new Category();
    $cat->setName($category['name']);
    $cat->setUrl($category['urlstring']);
    $map_categories[$category['id']] = $cat;

    /* Save category to mongodb */
    $dm->persist($cat);

    putline($category['name'] . " ");
}
putline("\n\n");

/* Setup category hieararchy */

putline("Setting up category relations... ");
foreach($categories as $old_cat) 
{
    $parent_id = $old_cat['parent'];
    if ($parent_id != 0) {
        $cat = $map_categories[$old_cat['id']];
        $parent = $map_categories[$parent_id];
        $cat->setParent($parent);
        $cat->parent_ref = $parent;
    }
}
putline("OK.\n");

/* Create products */
$map_products = array();

putline("Creating products... ");
foreach($products as $product) 
{
    $prod = new Product();

    $prod->setTitle($product['title']);
    $prod->setDescription($product['description']);
    $prod->setReference($product['reference']);
    $prod->setPrice(new Money(100 * intval($product['price'])));
    $map_products[$product['id']] = $prod;

    /* Get attributes */

    /* Save to mongodb */
    $dm->persist($prod);

    putline($product['title'] . " ");
}
putline("\n\n");

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

/* Sort images */
$i = 0;
foreach($products as $product) 
{
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
            /* Delete existing file */
            if (file_exists($new_path))
                unlink($new_path);

            $new_path = $img_path . $name;
            copy($old_path, $new_path);
            $i++;
        }
    }
}

putline("Sorted $i product images\n");

/* Flushing changes to mongodb */
$dm->flush();

putline("\n");
putline("All done.\n");

/******************************************************
    Functions
 ******************************************************/

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

    $values = findAttributeValues($id);
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

        $obj = new AttributeValue();
        $obj->setValue($value['value']);
        $obj->setPrice(new Money(100 * intval($value['price'])));
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
