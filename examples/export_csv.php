<?php

use LidlParser\Exception\ReceiptParseException;
use LidlParser\Parser;
use thiagoalessio\TesseractOCR\TesseractOcrException;

require_once '../vendor/autoload.php';

/*
This script will process all receipts in a given folder and export them as CSVs.
There are two types of CSV files:
- General receipt data, e.g., containing ID, total sum, payment method
- Product data, e.g., containing product name, single price, number of items
These two types of files can be generated for each receipt AND/OR as combined files
for all receipts. When exporting for each receipt is enabled, the script will skip the
receipts that already have been processed and continue with the receipts that have not
been exported yet.
*/

$dir_receipts = 'receipts';  // directory with receipts
$dir_exports = 'exports';    // directory for CSV export

$export_receipt = true;     // export CSVs for each receipt
$export_combined = true;    // export combined CSVs

// export file name suffixes
$suffix_receipts = "_receipts.csv";
$suffix_products = "_products.csv";
if($suffix_receipts == $suffix_products)
    throw new ErrorException("Receipt and product file name suffixes are identical");

// headers for CSV exports
$decimator = ";";
$header_receipts = '"Filename"' . $decimator .
'"ID"' . $decimator . 
'"Timestamp"' . $decimator .
'"Total"' . $decimator . 
'"PaymentMethod"' . "\n";
$header_products = '"Filename"' . $decimator .
'"Name"' . $decimator . 
'"PriceSingle"' . $decimator . 
'"Amount"' . $decimator .
'"Weight"' . $decimator . 
'"PriceTotal"' . $decimator . 
'"TaxCode"' . "\n";

// open combined export files and write header if necessary
if ($export_combined) {
    $new_receipts_file = !file_exists($dir_exports . "/export" . $suffix_receipts);
    $h_combined_receipts = fopen($dir_exports . "/export" . $suffix_receipts, 'a');
    if($new_receipts_file)
        fwrite($h_combined_receipts, $header_receipts);

    $new_products_file = !file_exists($dir_exports . "/export" . $suffix_products);
    $h_combined_products = fopen($dir_exports . "/export" . $suffix_products, 'a');
    if($new_products_file)
        fwrite($h_combined_products, $header_products);
}

echo "> Processing all receipts ... \n";
$files = scandir($dir_receipts);

foreach($files as $filename)
{
    // skip . and ..
    if($filename == '.' || $filename == '..')
        continue;

    // skip reciept if the export already exists
    if( $export_receipt
        && file_exists($dir_exports . "/" . $filename . $suffix_receipts)
        && file_exists($dir_exports . "/" . $filename . $suffix_products) )
        continue;

    try {
        // process receipt
        echo "> Reading receipt " . $filename . " ... \n";
        $receipt = Parser::parse($dir_receipts . "/" . $filename);

        // display receipt data
        echo "ID: " . $receipt->getID() . "\n";
        echo "Timestamp: " . $receipt->getTimestamp() . "\n";
        echo "Total: " . $receipt->getTotal() . "\n";
        echo "PaymentMethod: " . $receipt->getPaymentMethod() . "\n";

        // display product data
        foreach($receipt->getPositions() as $position) {
            echo str_pad($position->getName(), 20) . "\t" .
            $position->getPriceSingle() . "\tx " . 
            str_pad($position->getAmount(), 2) . " / " .
            str_pad($position->getWeight(), 5) . " kg\t\t" . 
            $position->getPriceTotal() . "\t" . 
            $position->getTaxCode() . "\n";
        }

        // write receipt data lines to CSV files
        $line = '"' . $filename . '"' . $decimator .
        '"' . $receipt->getID() . '"' . $decimator . 
        '"' . $receipt->getTimestamp() . '"' . $decimator . 
        '"' . $receipt->getTotal() . '"' . $decimator .
        '"' . $receipt->getPaymentMethod() . '"' . "\n";

        if($export_receipt) {
            $h_receipt = fopen($dir_exports . "/" . $filename . $suffix_receipts, 'wb');
            fwrite($h_receipt, $header_receipts);
            fwrite($h_receipt, $line);
            fclose($h_receipt);
        }
        if($export_combined)
            fwrite($h_combined_receipts, $line);

        // write product data line to CSV files
        if($export_receipt) {
            $h_product = fopen($dir_exports . "/" . $filename . $suffix_products, 'wb');
            fwrite($h_product, $header_products);
        }
        foreach($receipt->getPositions() as $position) {
            $line = '"' . $filename . '"' . $decimator .
            '"' . $position->getName() . '"' . $decimator . 
            '"' . $position->getPriceSingle() . '"' . $decimator . 
            '"' . $position->getAmount() . '"' . $decimator .
            '"' . $position->getWeight() . '"' . $decimator . 
            '"' . $position->getPriceTotal() . '"' . $decimator . 
            '"' . $position->getTaxCode() . '"' . "\n";

            if($export_receipt)
                fwrite($h_product, $line);
            if($export_combined)
                fwrite($h_combined_products, $line);
        }
        if($export_receipt)
            fclose($h_product);

    } catch (ReceiptParseException $e) {
        echo "Error when processing receipt:\n" . $e->getMessage();
        throw $e;
    }
}

// close combined CSV exports
if ($export_combined) {
    fclose($h_combined_receipts);
    fclose($h_combined_products);
}

echo "> Done with exporting all receipts";
