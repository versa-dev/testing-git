<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    Mage
 * @package     Mage_Tax
 * @copyright   Copyright (c) 2009 Irubin Consulting Inc. DBA Varien (http://www.varien.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Tax totals calculation model
 */
class Mage_Tax_Model_Sales_Total_Quote_Tax extends Mage_Sales_Model_Quote_Address_Total_Abstract
{
    /**
     * Tax module helper
     *
     * @var Mage_Tax_Helper_Data
     */
    protected $_helper;

    /**
     * Tax calculation model
     *
     * @var Mage_Tax_Model_Calculation
     */
    protected $_calculator;

    /**
     * Tax configuration object
     *
     * @var Mage_Tax_Model_Config
     */
    protected $_config;

    /**
     * Flag which is initialized when collect method is start.
     * Is used for checking if store tax and customer tax requests are similar
     *
     * @var bool
     */
    protected $_areTaxRequestsSimilar = false;


    protected $_roundingDeltas = array();
    protected $_baseRoundingDeltas = array();

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->setCode('tax');
        $this->_helper      = Mage::helper('tax');
        $this->_calculator  = Mage::getSingleton('tax/calculation');
        $this->_config      = Mage::getSingleton('tax/config');
    }

    /**
     * Collect tax totals for quote address
     *
     * @param   Mage_Sales_Model_Quote_Address $address
     * @return  Mage_Tax_Model_Sales_Total_Quote
     */
    public function collect(Mage_Sales_Model_Quote_Address $address)
    {
        parent::collect($address);
        $store = $address->getQuote()->getStore();
        $customer = $address->getQuote()->getCustomer();
        if ($customer) {
            $this->_calculator->setCustomer($customer);
        }

        if (!$address->getAppliedTaxesReset()) {
            $address->setAppliedTaxes(array());
        }

        $items = $address->getAllItems();
        if (!count($items)) {
            return $this;
        }
        $request = $this->_calculator->getRateRequest(
            $address,
            $address->getQuote()->getBillingAddress(),
            $address->getQuote()->getCustomerTaxClassId(),
            $store
        );

        $this->_areTaxRequestsSimilar = $this->_calculator->compareRequests(
            $this->_calculator->getRateOriginRequest($address->getQuote()->getStore()),
            $request
        );

        switch ($this->_config->getAlgorithm($store)) {
            case Mage_Tax_Model_Calculation::CALC_UNIT_BASE:
                $this->_unitBaseCalculation($address, $request);
                break;
            case Mage_Tax_Model_Calculation::CALC_ROW_BASE:
                $this->_rowBaseCalculation($address, $request);
                break;
            case Mage_Tax_Model_Calculation::CALC_TOTAL_BASE:
                $this->_totalBaseCalculation($address, $request);
                break;
            default:
                break;
        }

        /**
         * Subtract taxes from subtotal amount if prices include tax
         */
        if ($this->_usePriceIncludeTax($store)) {
            $subtotal       = $address->getSubtotalInclTax() - $address->getTotalAmount('tax');
            $baseSubtotal   = $address->getBaseSubtotalInclTax() - $address->getBaseTotalAmount('tax');
            $address->setTotalAmount('subtotal', $subtotal);
            $address->setBaseTotalAmount('subtotal', $baseSubtotal);
        }

        $this->_addAmount($address->getExtraTaxAmount());
        $this->_addBaseAmount($address->getBaseExtraTaxAmount());

        $this->_calculateShippingTax($address, $request);
        return $this;
    }

    /**
     * Check if price include tax should be used for calculations.
     * We are using price include tax just in case when catalog prices are including tax
     * and customer tax requist is same as store tax request
     *
     * @param $store
     * @return bool
     */
    protected function _usePriceIncludeTax($store)
    {
        if ($this->_config->priceIncludesTax($store) || $this->_config->getNeedUsePriceExcludeTax()) {
            return $this->_areTaxRequestsSimilar;
        }
        return false;
    }

    /**
     * Tax caclulation for shipping price
     *
     * @param   Mage_Sales_Model_Quote_Address $address
     * @param   Varien_Object $taxRateRequest
     * @return  Mage_Tax_Model_Sales_Total_Quote
     */
    protected function _calculateShippingTax(Mage_Sales_Model_Quote_Address $address, $taxRateRequest)
    {
        $store   = $address->getQuote()->getStore();
        $shippingTaxClass   = $this->_config->getShippingTaxClass($store);
        $shippingAmount     = $address->getShippingAmount();
        $baseShippingAmount = $address->getBaseShippingAmount();
        $shippingDiscountAmount = $address->getShippingDiscountAmount();
        $baseShippingDiscountAmount = $address->getBaseShippingDiscountAmount();

        /**
         * Subtract discount before calculate tax amount
         */
        if ($this->_config->applyTaxAfterDiscount($store)) {
            $calcAmount     = $shippingAmount - $shippingDiscountAmount;
            $baseCalcAmount = $baseShippingAmount - $baseShippingDiscountAmount;
        } else {
            $calcAmount     = $shippingAmount;
            $baseCalcAmount = $baseShippingAmount;
        }

        $shippingTax      = 0;
        $shippingBaseTax  = 0;

        if ($shippingTaxClass) {
            $taxRateRequest->setProductClassId($shippingTaxClass);
            $rate = $this->_calculator->getRate($taxRateRequest);
            if ($rate) {
                if ($this->_config->shippingPriceIncludesTax($store) && $this->_areTaxRequestsSimilar) {
                    $shippingTax    = $this->_calculator->calcTaxAmount($calcAmount, $rate, true, false);
                    $shippingBaseTax= $this->_calculator->calcTaxAmount($baseCalcAmount, $rate, true, false);
                    $shippingAmount-= $shippingTax;
                    $baseShippingAmount-=$shippingBaseTax;
                } else {
                    $shippingTax    = $this->_calculator->calcTaxAmount($calcAmount, $rate, false, false);
                    $shippingBaseTax= $this->_calculator->calcTaxAmount($baseCalcAmount, $rate, false, false);
                }
                $rateKey = (string) $rate;
                if (isset($this->_roundingDeltas[$rateKey])) {
                    $shippingTax+= $this->_roundingDeltas[$rateKey];
                }
                if (isset($this->_baseRoundingDeltas[$rateKey])) {
                    $shippingBaseTax+= $this->_baseRoundingDeltas[$rateKey];
                }
                $shippingTax        = $this->_calculator->round($shippingTax);
                $shippingBaseTax    = $this->_calculator->round($shippingBaseTax);

                $address->setTotalAmount('shipping', $shippingAmount);
                $address->setBaseTotalAmount('shipping', $baseShippingAmount);

                /**
                 * Provide additional attributes for apply discount on price include tax
                 */
                if ($this->_config->discountTax($store)) {
                    $address->setShippingAmountForDiscount($shippingAmount+$shippingTax);
                    $address->setBaseShippingAmountForDiscount($baseShippingAmount+$shippingBaseTax);
                }

                $this->_addAmount($shippingTax);
                $this->_addBaseAmount($shippingBaseTax);

                $applied = $this->_calculator->getAppliedRates($taxRateRequest);
                $this->_saveAppliedTaxes($address, $applied, $shippingTax, $shippingBaseTax, $rate);
            }
        }
        $address->setShippingTaxAmount($shippingTax);
        $address->setBaseShippingTaxAmount($shippingBaseTax);

        return $this;
    }

    /**
     * Calculate address tax amount based on one unit price and tax amount
     *
     * @param   Mage_Sales_Model_Quote_Address $address
     * @return  Mage_Tax_Model_Sales_Total_Quote
     */
    protected function _unitBaseCalculation(Mage_Sales_Model_Quote_Address $address, $taxRateRequest)
    {
        $items  = $address->getAllItems();
        foreach ($items as $item) {
            /**
             * Child item's tax we calculate for parent - that why we skip them
             */
            if ($item->getParentItemId()) {
                continue;
            }

            if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                foreach ($item->getChildren() as $child) {
                    $taxRateRequest->setProductClassId($child->getProduct()->getTaxClassId());
                    $rate = $this->_calculator->getRate($taxRateRequest);
                    $this->_calcUnitTaxAmount($child, $rate);

                    $this->_addAmount($child->getTaxAmount());
                    $this->_addBaseAmount($child->getBaseTaxAmount());

                    $applied = $this->_calculator->getAppliedRates($taxRateRequest);
                    $this->_saveAppliedTaxes($address, $applied, $child->getTaxAmount(), $child->getBaseTaxAmount(), $rate);
                }
                $this->_recalculateParent($item);
            }
            else {
                $taxRateRequest->setProductClassId($item->getProduct()->getTaxClassId());
                $rate = $this->_calculator->getRate($taxRateRequest);

                $this->_calcUnitTaxAmount($item, $rate);

                $this->_addAmount($item->getTaxAmount());
                $this->_addBaseAmount($item->getBaseTaxAmount());

                $applied = $this->_calculator->getAppliedRates($taxRateRequest);
                $this->_saveAppliedTaxes($address, $applied, $item->getTaxAmount(), $item->getBaseTaxAmount(), $rate);
            }
        }
        return $this;
    }

    /**
     * Calculate unit tax anount based on unit price
     *
     * @param   Mage_Sales_Model_Quote_Item_Abstract $item
     * @param   float $rate
     * @return  Mage_Tax_Model_Sales_Total_Quote
     */
    protected function _calcUnitTaxAmount(Mage_Sales_Model_Quote_Item_Abstract $item, $rate)
    {
        $store      = $item->getStore();
        $inclTax    = $this->_usePriceIncludeTax($store);
        $extra      = $item->getExtraTaxableAmount();
        $baseExtra  = $item->getBaseExtraTaxableAmount();

        if ($inclTax) {
            $price     = $store->roundPrice($item->getTaxCalcPrice()) + $extra;
            $basePrice = $store->roundPrice($item->getBaseTaxCalcPrice()) + $baseExtra;
        } else {
            if ($item->hasCustomPrice() && $this->_helper->applyTaxOnCustomPrice($store)) {
                $price     = $store->roundPrice($item->getCalculationPrice()) + $extra;
                $basePrice = $store->roundPrice($item->getBaseCalculationPrice()) + $baseExtra;
            } else {
                $price      = $store->roundPrice($item->getOriginalPrice()) + $item->getExtraTaxableAmount();
                $basePrice  = $store->roundPrice($item->getBaseOriginalPrice()) + $item->getBaseExtraTaxableAmount();
            }
        }

        $discountAmount     = $item->getDiscountAmount();
        $baseDiscountAmount = $item->getBaseDiscountAmount();
        $qty                = $item->getTotalQty();

        $item->setTaxPercent($rate);
        $rate = $rate/100;

        $calculationSequence = $this->_config->getCalculationSequence($store);
        switch ($calculationSequence) {
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_EXCL:
                $unitTax            = $this->_calculator->calcTaxAmount($price, $rate, $inclTax);
                $baseUnitTax        = $this->_calculator->calcTaxAmount($basePrice, $rate, $inclTax);
                break;
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_INCL:
                $unitTax            = $this->_calculator->calcTaxAmount($price, $rate, $inclTax);
                $baseUnitTax        = $this->_calculator->calcTaxAmount($basePrice, $rate, $inclTax);
                if ($inclTax) {
                    $item->setDiscountCalculationPrice($price);
                    $item->setBaseDiscountCalculationPrice($basePrice);
                } else {
                    $item->setDiscountCalculationPrice($price+$unitTax);
                    $item->setBaseDiscountCalculationPrice($basePrice+$baseUnitTax);
                }
                break;
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_EXCL:
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_INCL:
                $unitTax            = $this->_calculator->calcTaxAmount($price-$discountAmount/$qty, $rate, $inclTax);
                $baseUnitTax        = $this->_calculator->calcTaxAmount($basePrice-$baseDiscountAmount/$qty, $rate, $inclTax);
                break;
        }

        $totalTax       = $store->roundPrice($qty*$unitTax);
        $totalBaseTax   = $store->roundPrice($qty*$baseUnitTax);

        /**
         * Renew item amounts in case if we are working with price include tax
         */
        if ($inclTax) {
            if ($item->hasCustomPrice()) {
                $item->setCustomPrice($item->getPriceInclTax()-$unitTax);
                $item->setBaseCustomPrice($item->getBasePriceInclTax()-$baseUnitTax);
            } else {
                $item->setOriginalPrice($item->getPriceInclTax()-$unitTax);
                $item->setPrice($item->getBasePriceInclTax()-$baseUnitTax);
                $item->setBasePrice($item->getBasePriceInclTax()-$baseUnitTax);
            }
            $item->setRowTotal($item->getRowTotalInclTax()-$totalTax);
            $item->setBaseRowTotal($item->getBaseRowTotalInclTax()-$totalBaseTax);
        }

        $item->setTaxAmount($totalTax);
        $item->setBaseTaxAmount($totalBaseTax);
        return $this;
    }

    /**
     * Calculate address total tax based on row total
     *
     * @param   Mage_Sales_Model_Quote_Address $address
     * @param   Varien_Object $taxRateRequest
     * @return  Mage_Tax_Model_Sales_Total_Quote
     */
    protected function _rowBaseCalculation(Mage_Sales_Model_Quote_Address $address, $taxRateRequest)
    {
        $items  = $address->getAllItems();
        foreach ($items as $item) {
            /**
             * Child item's tax we calculate for parent - that why we skip them
             */
            if ($item->getParentItemId()) {
                continue;
            }
            if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                foreach ($item->getChildren() as $child) {
                    $rate = $this->_calculator->getRate(
                        $taxRateRequest->setProductClassId($child->getProduct()->getTaxClassId())
                    );
                    $this->_calcRowTaxAmount($child, $rate);
                    $this->_addAmount($child->getTaxAmount());
                    $this->_addBaseAmount($child->getBaseTaxAmount());

                    $applied = $this->_calculator->getAppliedRates($taxRateRequest);
                    $this->_saveAppliedTaxes($address, $applied, $child->getTaxAmount(), $child->getBaseTaxAmount(), $rate);
                }
                $this->_recalculateParent($item);
            }
            else {
                $rate = $this->_calculator->getRate(
                    $taxRateRequest->setProductClassId($item->getProduct()->getTaxClassId())
                );
                $this->_calcRowTaxAmount($item, $rate);
                $this->_addAmount($item->getTaxAmount());
                $this->_addBaseAmount($item->getBaseTaxAmount());

                $applied = $this->_calculator->getAppliedRates($taxRateRequest);
                $this->_saveAppliedTaxes($address, $applied, $item->getTaxAmount(), $item->getBaseTaxAmount(), $rate);
            }
        }
        return $this;
    }

    /**
     * Calculate item tax amount based on row total
     *
     * @param   Mage_Sales_Model_Quote_Item_Abstract $item
     * @param   float $rate
     * @return  Mage_Tax_Model_Sales_Total_Quote
     */
    protected function _calcRowTaxAmount($item, $rate)
    {
        $store = $item->getStore();
        $qty   = $item->getTotalQty();
        $inclTax = $this->_usePriceIncludeTax($store);

        if ($inclTax) {
            $subtotal       = $item->getTaxCalcRowTotal();
            $baseSubtotal   = $item->getBaseTaxCalcRowTotal();
        } else {
            if ($item->hasCustomPrice() && $this->_helper->applyTaxOnCustomPrice($store)) {
                $subtotal       = $item->getRowTotal();
                $baseSubtotal   = $item->getBaseRowTotal();
            } else {
                $subtotal       = $item->getTotalQty()*$item->getOriginalPrice();
                $baseSubtotal   = $item->getTotalQty()*$item->getBaseOriginalPrice();
            }
        }
        $subtotal           = $subtotal + $item->getExtraRowTaxableAmount();
        $baseSubtotal       = $baseSubtotal + $item->getBaseExtraRowTaxableAmount();

        $discountAmount     = $item->getDiscountAmount();
        $baseDiscountAmount = $item->getBaseDiscountAmount();

        $item->setTaxPercent($rate);
        $rate = $rate/100;

        $calculationSequence = $this->_helper->getCalculationSequence($store);
        switch ($calculationSequence) {
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_EXCL:
                $rowTax             = $this->_calculator->calcTaxAmount($subtotal, $rate, $inclTax);
                $baseRowTax         = $this->_calculator->calcTaxAmount($baseSubtotal, $rate, $inclTax);
                break;
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_INCL:
                $rowTax             = $this->_calculator->calcTaxAmount($subtotal, $rate, $inclTax);
                $baseRowTax         = $this->_calculator->calcTaxAmount($baseSubtotal, $rate, $inclTax);
                $discountPrice = $inclTax ? ($subtotal/$qty) : ($subtotal+$rowTax)/$qty;
                $baseDiscountPrice = $inclTax ? ($baseSubtotal/$qty) : ($baseSubtotal+$baseRowTax)/$qty;
                $item->setDiscountCalculationPrice($discountPrice);
                $item->setBaseDiscountCalculationPrice($baseDiscountPrice);
                break;
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_EXCL:
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_INCL:
                $rowTax             = $this->_calculator->calcTaxAmount($subtotal-$discountAmount, $rate, $inclTax);
                $baseRowTax         = $this->_calculator->calcTaxAmount($baseSubtotal-$baseDiscountAmount, $rate, $inclTax);
                break;
        }

        /**
         * Renew item amounts in case if we are working with price include tax
         */
        if ($inclTax) {
            $unitTax = $this->_calculator->round($rowTax/$qty);
            $baseUnitTax = $this->_calculator->round($baseRowTax/$qty);
            if ($item->hasCustomPrice()) {
                $item->setCustomPrice($item->getPriceInclTax()-$unitTax);
                $item->setBaseCustomPrice($item->getBasePriceInclTax()-$baseUnitTax);
            } else {
                $item->setOriginalPrice($item->getPriceInclTax()-$unitTax);
                $item->setPrice($item->getBasePriceInclTax()-$baseUnitTax);
                $item->setBasePrice($item->getBasePriceInclTax()-$baseUnitTax);
            }
            $item->setRowTotal($item->getRowTotalInclTax()-$rowTax);
            $item->setBaseRowTotal($item->getBaseRowTotalInclTax()-$baseRowTax);
        }

        $item->setTaxAmount($rowTax);
        $item->setBaseTaxAmount($baseRowTax);
        return $this;
    }

    /**
     * Calculate address total tax based on address subtotal
     *
     * @param   Mage_Sales_Model_Quote_Address $address
     * @param   Varien_Object $taxRateRequest
     * @return  Mage_Tax_Model_Sales_Total_Quote
     */
    protected function _totalBaseCalculation(Mage_Sales_Model_Quote_Address $address, $taxRateRequest)
    {
        $items      = $address->getAllItems();
        $store      = $address->getQuote()->getStore();
        $taxGroups  = array();

        foreach ($items as $item) {
            /**
             * Child item's tax we calculate for parent - that why we skip them
             */
            if ($item->getParentItemId()) {
                continue;
            }

            if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                foreach ($item->getChildren() as $child) {
                    $rate = $this->_calculator->getRate(
                        $taxRateRequest->setProductClassId($child->getProduct()->getTaxClassId())
                    );
                    $taxGroups[(string)$rate]['applied_rates'] = $this->_calculator->getAppliedRates($taxRateRequest);
                    $this->_aggregateTaxPerRate($child, $rate, $taxGroups);
                }
                $this->_recalculateParent($item);
            } else {
                $rate = $this->_calculator->getRate(
                    $taxRateRequest->setProductClassId($item->getProduct()->getTaxClassId())
                );
                $taxGroups[(string)$rate]['applied_rates'] = $this->_calculator->getAppliedRates($taxRateRequest);
                $this->_aggregateTaxPerRate($item, $rate, $taxGroups);
            }
        }

        $inclTax = $this->_usePriceIncludeTax($store);
        foreach ($taxGroups as $rateKey => $data) {
            $rate = (float) $rateKey;
            $totalTax = $this->_calculator->calcTaxAmount(array_sum($data['totals']), $rate, $inclTax);
            $baseTotalTax = $this->_calculator->calcTaxAmount(array_sum($data['base_totals']), $rate, $inclTax);
            $this->_addAmount($totalTax);
            $this->_addBaseAmount($baseTotalTax);
            $this->_saveAppliedTaxes($address, $data['applied_rates'], $totalTax, $baseTotalTax, $rate);
        }
        return $this;
    }

    /**
     * Aggregate row totals per tax rate in array
     *
     * @param   Mage_Sales_Model_Quote_Item_Abstract $item
     * @param   float $rate
     * @param   array $taxGroups
     * @return  Mage_Tax_Model_Sales_Total_Quote
     */
    protected function _aggregateTaxPerRate($item, $rate, &$taxGroups)
    {
        $store   = $item->getStore();
        $inclTax = $this->_usePriceIncludeTax($store);

        if ($inclTax) {
            $subtotal       = $item->getTaxCalcRowTotal();
            $baseSubtotal   = $item->getBaseTaxCalcRowTotal();
        } else {
            if ($item->hasCustomPrice() && $this->_helper->applyTaxOnCustomPrice($store)) {
                $subtotal       = $item->getRowTotal();
                $baseSubtotal   = $item->getBaseRowTotal();
            } else {
                $subtotal       = $item->getTotalQty()*$item->getOriginalPrice();
                $baseSubtotal   = $item->getTotalQty()*$item->getBaseOriginalPrice();
            }
        }
        $discountAmount     = $item->getDiscountAmount();
        $baseDiscountAmount = $item->getBaseDiscountAmount();
        $qty                = $item->getTotalQty();
        $rateKey            = (string) $rate;
        /**
         * Add extra amounts which can be taxable too
         */
        $calcTotal          = $subtotal + $item->getExtraRowTaxableAmount();
        $baseCalcTotal      = $baseSubtotal + $item->getBaseExtraRowTaxableAmount();

        $item->setTaxPercent($rate);
        if (!isset($taxGroups[$rateKey]['totals'])) {
            $taxGroups[$rateKey]['totals'] = array();
        }
        if (!isset($taxGroups[$rateKey]['totals'])) {
            $taxGroups[$rateKey]['base_totals'] = array();
        }

        $calculationSequence = $this->_helper->getCalculationSequence($store);
        switch ($calculationSequence) {
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_EXCL:
                $rowTax             = $this->_calculator->calcTaxAmount($calcTotal, $rate, $inclTax, false);
                $baseRowTax         = $this->_calculator->calcTaxAmount($baseCalcTotal, $rate, $inclTax, false);
                break;
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_INCL:
                $rowTax             = $this->_calculator->calcTaxAmount($calcTotal, $rate, $inclTax, false);
                $baseRowTax         = $this->_calculator->calcTaxAmount($baseCalcTotal, $rate, $inclTax, false);
                $discountPrice = $inclTax ? ($subtotal/$qty) : ($subtotal+$rowTax)/$qty;
                $baseDiscountPrice = $inclTax ? ($baseSubtotal/$qty) : ($baseSubtotal+$baseRowTax)/$qty;
                $item->setDiscountCalculationPrice($discountPrice);
                $item->setBaseDiscountCalculationPrice($baseDiscountPrice);
                break;
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_EXCL:
            case Mage_Tax_Model_Calculation::CALC_TAX_AFTER_DISCOUNT_ON_INCL:
                $calcTotal          = $calcTotal-$discountAmount;
                $baseCalcTotal      = $baseCalcTotal-$baseDiscountAmount;
                $rowTax             = $this->_calculator->calcTaxAmount($calcTotal, $rate, $inclTax, false);
                $baseRowTax         = $this->_calculator->calcTaxAmount($baseCalcTotal, $rate, $inclTax, false);
                break;
        }

        /**
         * "Delta" rounding
         */
        $delta      = isset($this->_roundingDeltas[$rateKey]) ? $this->_roundingDeltas[$rateKey] : 0;
        $baseDelta  = isset($this->_baseRoundingDeltas[$rateKey]) ? $this->_baseRoundingDeltas[$rateKey] : 0;

        $rowTax     += $delta;
        $baseRowTax += $baseDelta;

        $this->_roundingDeltas[$rateKey]     = $rowTax - $this->_calculator->round($rowTax);
        $this->_baseRoundingDeltas[$rateKey] = $baseRowTax - $this->_calculator->round($baseRowTax);
        $rowTax     = $this->_calculator->round($rowTax);
        $baseRowTax = $this->_calculator->round($baseRowTax);

        /**
         * Renew item amounts in case if we are working with price include tax
         */
        if ($inclTax) {
            $unitTax = $this->_calculator->round($rowTax/$qty);
            $baseUnitTax = $this->_calculator->round($baseRowTax/$qty);
            if ($item->hasCustomPrice()) {
                $item->setCustomPrice($item->getPriceInclTax()-$unitTax);
                $item->setBaseCustomPrice($item->getBasePriceInclTax()-$baseUnitTax);
            } else {
                $item->setOriginalPrice($item->getPriceInclTax()-$unitTax);
                $item->setPrice($item->getBasePriceInclTax()-$baseUnitTax);
                $item->setBasePrice($item->getBasePriceInclTax()-$baseUnitTax);
            }
            $item->setRowTotal($item->getRowTotalInclTax()-$rowTax);
            $item->setBaseRowTotal($item->getBaseRowTotalInclTax()-$baseRowTax);
        }

        $item->setTaxAmount($rowTax);
        $item->setBaseTaxAmount($baseRowTax);

        $taxGroups[$rateKey]['totals'][]        = $calcTotal;
        $taxGroups[$rateKey]['base_totals'][]   = $baseCalcTotal;
        return $this;
    }

    /**
     * Recalculate parent item amounts base on children data
     *
     * @param   Mage_Sales_Model_Quote_Item_Abstract $item
     * @return  Mage_Tax_Model_Sales_Total_Quote
     */
    protected function _recalculateParent(Mage_Sales_Model_Quote_Item_Abstract $item)
    {
        $calculationPrice       = 0;
        $baseCalculationPrice   = 0;
        $rowTaxAmount           = 0;
        $baseRowTaxAmount       = 0;
        $rowTotal               = 0;
        $baseRowTotal           = 0;
        foreach ($item->getChildren() as $child) {
            $calculationPrice       += $child->getCalculationPrice();
            $baseCalculationPrice   += $child->getBaseCalculationPrice();
            $rowTaxAmount           += $child->getTaxAmount();
            $baseRowTaxAmount       += $child->getBaseTaxAmount();
            $rowTotal               += $child->getRowTotal();
            $baseRowTotal           += $child->getBaseRowTotal();
        }
        $item->setOriginalPrice($calculationPrice);
        $item->setPrice($baseCalculationPrice);
        $item->setTaxAmount($rowTaxAmount);
        $item->setBaseTaxAmount($baseRowTaxAmount);
        $item->setRowTotal($rowTotal);
        $item->setBaseRowTotal($baseRowTotal);
        return $this;
    }

    /**
     * Collect applied tax rates information on address level
     *
     * @param   Mage_Sales_Model_Quote_Address $address
     * @param   array $applied
     * @param   float $amount
     * @param   float $baseAmount
     * @param   float $rate
     */
    protected function _saveAppliedTaxes(Mage_Sales_Model_Quote_Address $address, $applied, $amount, $baseAmount, $rate)
    {
        $previouslyAppliedTaxes = $address->getAppliedTaxes();
        $process = count($previouslyAppliedTaxes);

        foreach ($applied as $row) {
            if (!isset($previouslyAppliedTaxes[$row['id']])) {
                $row['process'] = $process;
                $row['amount'] = 0;
                $row['base_amount'] = 0;
                $previouslyAppliedTaxes[$row['id']] = $row;
            }

            if (!is_null($row['percent'])) {
                $row['percent'] = $row['percent'] ? $row['percent'] : 1;
                $rate = $rate ? $rate : 1;

                $appliedAmount = $amount/$rate*$row['percent'];
                $baseAppliedAmount = $baseAmount/$rate*$row['percent'];
            } else {
                $appliedAmount = 0;
                $baseAppliedAmount = 0;
                foreach ($row['rates'] as $rate) {
                    $appliedAmount += $rate['amount'];
                    $baseAppliedAmount += $rate['base_amount'];
                }
            }


            if ($appliedAmount || $previouslyAppliedTaxes[$row['id']]['amount']) {
                $previouslyAppliedTaxes[$row['id']]['amount'] += $appliedAmount;
                $previouslyAppliedTaxes[$row['id']]['base_amount'] += $baseAppliedAmount;
            } else {
                unset($previouslyAppliedTaxes[$row['id']]);
            }
        }
        $address->setAppliedTaxes($previouslyAppliedTaxes);
    }

    /**
     * Add tax totals information to address object
     *
     * @param   Mage_Sales_Model_Quote_Address $address
     * @return  Mage_Tax_Model_Sales_Total_Quote
     */
    public function fetch(Mage_Sales_Model_Quote_Address $address)
    {
        $applied= $address->getAppliedTaxes();
        $store  = $address->getQuote()->getStore();
        $amount = $address->getTaxAmount();
        $area   = null;
        if ($this->_config->displayCartTaxWithGrandTotal($store) && $address->getGrandTotal()) {
            $area = 'taxes';
        }

        if (($amount!=0) || ($this->_config->displayCartZeroTax($store))) {
            $address->addTotal(array(
                'code'      => $this->getCode(),
                'title'     => Mage::helper('tax')->__('Tax'),
                'full_info' => $applied ? $applied : array(),
                'value'     => $amount,
                'area'      => $area
            ));
        }

        $store = $address->getQuote()->getStore();
        /**
         * Modify subtotal
         */
        if ($this->_config->displayCartSubtotalBoth($store) || $this->_config->displayCartSubtotalInclTax($store)) {
            if ($address->getSubtotalInclTax() > 0) {
                $subtotalInclTax = $address->getSubtotalInclTax();
            } else {
                $subtotalInclTax = $address->getSubtotal()+$address->getTaxAmount()-$address->getShippingTaxAmount();
            }

            $address->addTotal(array(
                'code'      => 'subtotal',
                'title'     => Mage::helper('sales')->__('Subtotal'),
                'value'     => $subtotalInclTax,
                'value_incl_tax' => $subtotalInclTax,
                'value_excl_tax' => $address->getSubtotal(),
            ));
        }

        return $this;
    }

    /**
     * Process model configuration array.
     * This method can be used for changing totals collect sort order
     *
     * @param   array $config
     * @param   store $store
     * @return  array
     */
    public function processConfigArray($config, $store)
    {
        $calculationSequence = $this->_helper->getCalculationSequence($store);
         switch ($calculationSequence) {
            case Mage_Tax_Model_Calculation::CALC_TAX_BEFORE_DISCOUNT_ON_INCL:
                $config['before'][] = 'discount';
                break;
            default:
                $config['after'][] = 'discount';
                break;
        }
        return $config;
    }
}
