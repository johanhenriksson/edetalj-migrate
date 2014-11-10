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

putline("Loading from: $file\n");

require $file;

putline("Ok.\n");

/* Connect to MongoDB */

putline("Target database (will be cleared): ");
$dbname = getline();

$config = new Configuration();
$config->setProxyDir(__DIR__ . '/../edetalj/cache/proxies');
$config->setProxyNamespace('Proxies');
$config->setHydratorDir(__DIR__ . '/../edetalj/cache/hydrators');
$config->setHydratorNamespace('Hydrators');
$config->setMetadataDriverImpl(new YamlDriver(__DIR__ . '/../edetalj/metadata'));
$config->setDefaultDB($dbname);

$dm = DocumentManager::create(new Connection(), $config);

/* All done. */

putline("Ready. Press enter to migrate.");
getline();

/* Create categories */
$catmap = array();

putline("Creating categories... ");
foreach($categories as $category) {
    putline($category['name'] . "\n");
    $cat = new Category();
    $cat->setName($category['name']);
    $cat->setUrl($category['urlstring']);
    $catmap[$category['id']] = $cat;

    /* Save category to mongodb */
    $dm->persist($cat);

    putline($category['name'] . " ");
}
putline("\n");

/* Setup category hieararchy */

putline("Setting up category relations... ");
foreach($categories as $old_cat) {
    $parent_id = $old_cat['parent'];
    if ($parent_id != 0) {
        $cat = $catmap[$old_cat['id']];
        $parent = $catmap[$parent_id];
        $cat->setParent($parent);

        /* Save changes */
    }
}
putline("Ok.\n");

/* Create products */

putline("Creating products... ");
foreach($products as $product) {
    $prod = new Product();

    $prod->setTitle($product['title']);
    $prod->setDescription($product['description']);
    $prod->setReference($product['reference']);
    $prod->setPrice(new Money(100 * intval($product['price'])));

    /* Save to mongodb */
    $dm->persist($prod);

    putline($product['title'] . " ");
}
putline("\n");

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

/* Add attributes */

/* Sort images */

/* Add products to categories */

/* Flushing changes to mongodb */
$dm->flush();
