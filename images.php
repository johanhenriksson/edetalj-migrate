#!/usr/bin/php
<?php
/* edetalj standard 
define('UC_PUBLIC_KEY', '169bf4c6eccf97126d89');
define('UC_SECRET_KEY', '853b1e006d74650b27a7');
 */
/* jsenergi */
define('UC_PUBLIC_KEY', 'e61e49a2b9305e4788dd');
define('UC_SECRET_KEY', 'a1db595be36043d3123a');

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
$img_cache = array();

/*
 * Version 1.0 -> 2.0 Database Migration tool
 * Johan Henriksson, Nov 2014
 *
 * TODO:
 *  - Add pages for products & categories with their metadata information
 */

putline("**********************\n");
putline("Edetalj image upload tool\n");
putline("**********************\n");
putline("\n");

chmod(__DIR__ . '/images', 0777);

/* Connect to MongoDB */

putline("Source database: "); 
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
$products = $dm->createQueryBuilder('webshop\Product')
        ->getQuery()
        ->execute();

/* Uploadcare API */

$uc_api = new Uploadcare\Api(UC_PUBLIC_KEY, UC_SECRET_KEY);
$upload_images = false;

putline("\n");
putline("Ready. Press enter to upload.\n");
getline();


/* Create products */
$map_products = array();

$i = 0;
$j = 0;
putline("Looping through products... ");

foreach($products as $product) 
{
    $image = __DIR__ . '/img/' . $product->getReference() . '.jpg';
    $j++;
    if (!file_exists($image))
        continue;

    echo "$i ($j) " . $product->getTitle() . ": $image\n";


    $img = uploadImage($image);
    $product->clearImages();
    $product->addImage($img);

    $i++;
    if ($i % 50) {
        $dm->flush();
        $dm->clear();
    }
}

$dm->flush();

putline("\n");
putline("Done. $i products got images\n");

putline("All done.\n");

/******************************************************
    Functions
 ******************************************************/

function uploadImage($path) 
{
    global $uc_api;
    global $img_cache;

    $cached = isset($img_cache[$path]);

    if (!$cached) {
        $file = $uc_api->uploader->fromPath($path);
        $file->store();
        $id = $file->getFileId();

        echo "uploaded $path --> $id\n";
        $image = new ProductImage($id);
        $img_cache[$path] = $image;
        return $image;
    }

    echo "image cache hit\n"; 
    return $img_cache[$path];
}
