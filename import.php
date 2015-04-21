<?php
/*
 * E-detalj CSV Importer 
 * Johan Henriksson, Feb 2015
 */

$file = fopen("products.csv", "r");
$header = print_r(fgetcsv($file, 0, ';', "'"));

/*
 * Expected format:
 *
 * 0    Art Nr
 * 1    Title
 * 2    Description
 * 3    Top Cat
 * 4    Cat 1
 * 5    Cat 2
 * 6    Price
 */

function parseCategory($cat1, $cat2, $cat3) 
{
    global $categories;
    if (strlen($cat3) > 0) {
        $category = array(
            "path" => "$cat1 / $cat2 / $cat3",
            "name" => $cat3,
            "parent" => "$cat1 / $cat2",
        );
        if (!isset($categories[$category['parent']]))
            parseCategory($cat1, $cat2, "");
        if (!isset($categories[$category['path']]))
            $categories[$category['path']] = $category;
        return $category;
    }
    if (strlen($cat2) > 0) {
        $category = array(
            "path" => "$cat1 / $cat2",
            "name" => $cat2,
            "parent" => $cat1
        );
        if (!isset($categories[$category['parent']]))
            parseCategory($cat1, "", "");
        if (!isset($categories[$category['path']]))
            $categories[$category['path']] = $category;
        return $category;
    }

    $category = array(
        "path" => $cat1,
        "name" => $cat1,
        "parent" => null
    );

    if (!isset($categories[$category['path']]))
        $categories[$category['path']] = $category;

    return $category;
}

$categories = array();
$products = array();

$i = 0;
while(!feof($file)) 
{
    $row = fgetcsv($file, 0, ';', "'");

    $price = priceToFloat($row[6]);

    $cat1 = trim($row[3]);
    $cat2 = trim($row[4]);
    $cat3 = trim($row[5]);

    $category = parseCategory($cat1, $cat2, $cat3);

    $out = array(
        'path' => $category['path'] . ' / ' . trim($row[0]) . ': ' . trim($row[1]), // use reference as id
        'reference' => trim($row[0]),
        'title' => trim($row[1]),
        'description' => trim($row[2]),
        'price' => $price,
        'category' => $category['path'], // category path as category id
    );

    if (strlen($out['title']) == 0)
        continue;

    $img = sprintf("%s/img/%s.jpg", __DIR__, $row[0]);
    if (file_exists($img)) {
        $out['image'] = $img;
        $i++;
    }

    $products[] = $out;
}

/* dump value array */
$categories = array_values($categories);
echo "$i products with images\n";

$catcount = count($categories);
echo "Parsed $catcount categories\n";

$prodcount = count($products);
echo "Parsed $prodcount products\n";



function priceToFloat($s)
{
    // convert "," to "."
    $s = str_replace(',', '.', $s);

    // remove everything except numbers and dot "."
    $s = preg_replace("/[^0-9\.]/", "", $s);

    // remove all seperators from first part and keep the end
    $s = str_replace('.', '',substr($s, 0, -3)) . substr($s, -3);

    // return float
    return (float) $s;
}
?>
