<?php

namespace LidlParser;

use Carbon\Carbon;
use LidlParser\Exception\PositionNotFoundException;
use LidlParser\Exception\ReceiptParseException;
use thiagoalessio\TesseractOCR\TesseractOCR;
use thiagoalessio\TesseractOCR\TesseractOcrException;

class Receipt {
    private $rawReceipt;
    private $explodedReceipt;

    /**
     * @param string $imagePath
     * @throws TesseractOcrException
     */
    public function __construct(string $imagePath) {
        $ocr                   = new TesseractOCR($imagePath);
        $ocr->psm(4);       // page segmentation mode 4: Assume a single column of text of variable sizes.
        $ocr->lang('deu');  // german language to support umlaut
        $this->rawReceipt      = $ocr->run();
        $this->rawReceipt      = str_replace('@', '0', $this->rawReceipt); //Maybe there is a better solution to handle these ocr problem?
        $this->rawReceipt      = str_replace('®', '0', $this->rawReceipt);
        $this->explodedReceipt = explode("\n", $this->rawReceipt);
    }

    /**
     * @throws ReceiptParseException
     */
    public function getID(): int {
        if(preg_match('/(\d+) (\d+)\/(\d+) (\d{2}).(\d{2})/', $this->rawReceipt, $match)) {
            return (int)$match[1];
        }
        throw new ReceiptParseException();
    }

    /**
     * @return float
     * @throws ReceiptParseException
     */
    public function getTotal(): float {
        if(preg_match('/zu zahlen (-?\d+,\d{2})/', $this->rawReceipt, $match))
            return (float)str_replace(',', '.', $match[1]);
        throw new ReceiptParseException();
    }

    /**
     * @return string
     * @throws ReceiptParseException
     */
    public function getPaymentMethod(): string {
        $next = false;
        foreach($this->explodedReceipt as $row)
            if($next) {
                // catch possible empty lines between sum and payment method
                if(trim($row)=="")
                    continue;
                if(!preg_match("/(.*) \d+,\d{2}/", $row, $match))
                    throw new ReceiptParseException();
                return $match[1];
            } elseif(substr(trim($row), 0, 9) == "zu zahlen")
                $next = true;
        throw new ReceiptParseException();
    }

    /**
     * @return bool
     */
    public function hasPayedCashless(): bool {
        return preg_match('/(Kreditkarte|Karte)/', $this->rawReceipt);
    }

    /**
     * @return Carbon
     * @throws ReceiptParseException
     */
    public function getTimestamp(): Carbon {
        if(preg_match('/(\d{2}).(\d{2}).(\d{2}) (\d{2}):(\d{2})/', $this->rawReceipt, $match)) {
            return Carbon::create("20" . $match[3], $match[2], $match[1], $match[4], $match[5], 0, 'Europe/Berlin');
        }
        throw new ReceiptParseException();
    }

    /**
     * @return int
     * @throws ReceiptParseException
     */
    private function getProductStartLine(): int {
        foreach(explode("\n", $this->rawReceipt) as $line => $content)
            if(trim($content) == "EUR")
                return $line + 1;
        // in case the "EUR" heading is not found, revert to "Bonkopie" header
        foreach(explode("\n", $this->rawReceipt) as $line => $content)
            if(trim($content) == "Bonkopie")
                return $line + 1;
        throw new ReceiptParseException();
    }

    /**
     * @return int
     * @throws ReceiptParseException
     */
    private function getProductEndLine(): int {
        foreach(explode("\n", $this->rawReceipt) as $line => $content)
            if(substr(trim($content), 0, 9) == "zu zahlen")
                return $line - 1;
        throw new ReceiptParseException();
    }

    /**
     * @param string $name
     * @return Position
     * @throws PositionNotFoundException|ReceiptParseException
     */
    public function getPositionByName(string $name): Position {
        foreach($this->getPositions() as $position) {
            if($position->getName() == $name)
                return $position;
        }
        throw new PositionNotFoundException("Position '$name' not found");
    }

    /**
     * TODO: Wiege und mehrzahl
     * @return array
     * @throws ReceiptParseException
     */
    public function getPositions(): array {
        $positions    = [];
        $lastPosition = NULL;

        for($lineNr = $this->getProductStartLine(); $lineNr <= $this->getProductEndLine(); $lineNr++) {
            //echo $this->explodedReceipt[$lineNr] ."\n";
            if(trim($this->explodedReceipt[$lineNr]) == "")
                continue;   // skip empty lines
            elseif($this->isProductLine($lineNr)) {

                if($lastPosition !== NULL) {
                    $positions[]  = $lastPosition;
                    $lastPosition = NULL;
                }

                if(preg_match('/(.*) (-?\d+,\d{2}).*x.*(\d+) (-?\d+,\d{2}) ([A-Z])/', $this->explodedReceipt[$lineNr], $match)) {
                    // new receipts have the number of items in the same line as the product name
                    // example: "Productname 0,99 x 2 1,98 A"
                    $lastPosition = new Position();
                    $lastPosition->setName(trim($match[1]));
                    $lastPosition->setPriceSingle((float)str_replace(',', '.', $match[2]));
                    $lastPosition->setAmount((int)$match[3]);
                    $lastPosition->setPriceTotal((float)str_replace(',', '.', $match[4]));
                    $lastPosition->setTaxCode($match[5]);
                } elseif(preg_match('/(.*) (-?\d+,\d{2}) ([A-Z])/', $this->explodedReceipt[$lineNr], $match)) {
                    // example: "Productname 0,99 A"
                    $lastPosition = new Position();
                    $lastPosition->setName(trim($match[1]));
                    $lastPosition->setPriceTotal((float)str_replace(',', '.', $match[2]));
                    $lastPosition->setTaxCode($match[3]);
                } elseif(preg_match('/(.*) (-?\d+,\d{2})/', $this->explodedReceipt[$lineNr], $match)) {
                    // example: "Productname 0,99"
                    $lastPosition = new Position();
                    $lastPosition->setName(trim($match[1]));
                    $lastPosition->setPriceTotal((float)str_replace(',', '.', $match[2]));
                } else throw new ReceiptParseException("Error while parsing Product line");

            } elseif ($this->isAmountLine($lineNr)) {

                if (preg_match('/(\d+).*x.*(-?\d+,\d{2})/', $this->explodedReceipt[$lineNr], $match)) {
                    // old receipts have the number of items in the line below the product name
                    // example: "Productname 1,98 A" followed by "2 x 0,99"
                    $lastPosition->setAmount((int)$match[1]);
                    $lastPosition->setPriceSingle((float)str_replace(',', '.', $match[2]));
                } else throw new ReceiptParseException("Error while parsing Amount line");

            } elseif ($this->isWeightLine($lineNr)) {

                if (preg_match('/(-?\d+,\d{3}) kg x *(-?\d+,\d{2}) EUR/', $this->explodedReceipt[$lineNr], $match)) {
                    // example: "Productname 1,00 A" followed by "0,500 kg x 2,00 EUR/kg"
                    $lastPosition->setWeight((float)str_replace(',', '.', $match[1]));
                    $lastPosition->setPriceSingle((float)str_replace(',', '.', $match[2]));
                } elseif (preg_match('/Handeingabe E-Bon *(-?\d+,\d{3}) kg/', $this->explodedReceipt[$lineNr], $match)) {
                    // example: TODO
                    $lastPosition->setWeight((float)str_replace(',', '.', $match[1]));
                } else throw new ReceiptParseException("Error while parsing Weight line");

            } elseif ($this->isDiscountLine($lineNr)) {

                if (preg_match('/rabatt.*(-\d+,\d{2})/i', $this->explodedReceipt[$lineNr], $match)) {
                    // example: "Productname 1,98 A" followed by "Rabatt -0,10"
                    $lastPosition->addDiscount((float)str_replace(',', '.', $match[1]));
                } else throw new ReceiptParseException("Error while parsing Discount line");

            } else throw new ReceiptParseException("Error while parsing unknown receipt line");

        }

        if($lastPosition !== NULL)
            $positions[] = $lastPosition;

        if(count($positions) == 0)
            throw new ReceiptParseException("Cannot parse any products on receipt");

        return $positions;
    }

    private function isWeightLine($lineNr) {
        return strpos($this->explodedReceipt[$lineNr], 'EUR/kg') !== false;
    }

    private function isAmountLine($lineNr) {
        return preg_match('/^\d+ *x *-?\d+,\d/', $this->explodedReceipt[$lineNr]);
    }

    private function isDiscountLine($lineNr) {
        return preg_match('/rabatt.*-\d,\d{2}/i', $this->explodedReceipt[$lineNr]);
    }

    private function isProductLine($lineNr) {
        return !$this->isWeightLine($lineNr) && !$this->isAmountLine($lineNr) && !$this->isDiscountLine($lineNr);
    }
}