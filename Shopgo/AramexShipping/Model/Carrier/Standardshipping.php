<?php

namespace ShopGo\AramexShipping\Model\Carrier;

use Magento\Framework\Module\Dir;
use Magento\Quote\Model\Quote\Address\RateResult\Error;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Rate\Result;
use Magento\Framework\Xml\Security;


class Standardshipping extends AbstractCarrierOnline implements \Magento\Shipping\Model\Carrier\CarrierInterface
{
    const CODE = 'aramex';

    protected $_code = self::CODE;

    protected $_request = null;

    protected $_result = null;

    protected $_storeManager;

    protected $_productCollectionFactory;

    protected $_rateServiceWsdl;

    protected $directoryHelper;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \ShopGo\AramexShipping\Helper\Logger\Logger $logger
     * @param Security $xmlSecurity
     * @param \Magento\Shipping\Model\Simplexml\ElementFactory $xmlElFactory
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory
     * @param \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory
     * @param \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     * @param \Magento\Directory\Model\CountryFactory $countryFactory
     * @param \Magento\Directory\Model\CurrencyFactory $currencyFactory
     * @param \Magento\Directory\Helper\Data $directoryData
     * @param \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Module\Dir\Reader $configReader
     * @param \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory
     * @param array $data
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \ShopGo\AramexShipping\Helper\Logger\Logger $logger,
        Security $xmlSecurity,
        \Magento\Shipping\Model\Simplexml\ElementFactory $xmlElFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory,
        \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory,
        \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Directory\Helper\Data $directoryData,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Module\Dir\Reader $configReader,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \ShopGo\AramexShipping\Helper\Data $helper,
        array $data = []
    ) {
        $this->directoryHelper           = $directoryData;
        $this->_helper                   = $helper;
        $this->_logger                   = $logger;
        $this->_storeManager             = $storeManager;
        $this->_productCollectionFactory = $productCollectionFactory;

        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElFactory,
            $rateFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $directoryData,
            $stockRegistry,
            $data
        );
        $wsdlPath = $configReader->getModuleDir(Dir::MODULE_ETC_DIR, 'ShopGo_AramexShipping') . '/wsdl/';
        $this->_rateServiceWsdl = $wsdlPath . 'aramex-rates-calculator-wsdl.wsdl';
    }

    protected function _createSoapClient($wsdl, $trace = false)
    {
        $client = new \SoapClient($wsdl, ['trace' => $trace]);
        return $client;
    }

    protected function _createRateSoapClient()
    {
        return $this->_createSoapClient($this->_rateServiceWsdl);
    }

    public function collectRates(RateRequest $request)
    {
        if (!$this->canCollectRates()) {
            return $this->getErrorMessage();
        }

        $requestAramex = clone $request;
        $this->setRequest($requestAramex);

        $params = $this->buildAramexReq();
        $this->sendAramexReq($params);

        $rate       = $this->_rateFactory->create();
        $resultQuote = $this->_result;

        if($resultQuote == false) {
            return $this->failAramex($rate);
        }else{
            return $this->addAramexRate($rate, $resultQuote);
        }
    }

    public function setRequest(\Magento\Framework\DataObject $request)
    {

        $this->_request = $request;
        $this->setStore($request->getStoreId());

        $reqObject = new \Magento\Framework\DataObject();
        
        $reqObject->setUserName($this->getConfigData('username'));
        $reqObject->setPassword($this->getConfigData('password'));
        $reqObject->setAccountNumber($this->getConfigData('accountnumber'));
        $reqObject->setAccountEntity($this->getConfigData('accountentity'));
        $reqObject->setAccountPin($this->getConfigData('accountpin'));
        $reqObject->setAccountCountryCode($this->getConfigData('accountcountrycode'));

        $origCountry = $this->_scopeConfig->getValue(
                        \Magento\Sales\Model\Order\Shipment::XML_PATH_STORE_COUNTRY_ID,
                        \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                        $request->getStoreId()
                     );

        $reqObject->setOrigCountry($this->_countryFactory->create()->load($origCountry)->getData('iso2_code'));

        $reqObject->setOrigCity(
                $this->_scopeConfig->getValue(
                    \Magento\Sales\Model\Order\Shipment::XML_PATH_STORE_CITY,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $request->getStoreId()
                )
            );

        $destCountry = $request->getDestCountryId();

        $reqObject->setDestCountry($this->_countryFactory->create()->load($destCountry)->getData('iso2_code'));

        $reqObject->setDestCity($request->getDestCity());
        $reqObject->setDestPostal($request->getDestPostcode());

        if ($reqObject->getDestCountry() == $reqObject->getOrigCountry()){
            $reqObject->setProductGroup('DOM');
            $reqObject->setProductType($this->getConfigData('domesticproducttype'));
        }
        else{
            $reqObject->setProductGroup('EXP');
            $reqObject->setProductType($this->getConfigData('producttype'));
        }
        
        $this->setRawRequest($reqObject);

        return $this;
    }
     public function buildAramexReq(){

        $reqObject      = $this->_rawRequest;

        $pices  = $this->getOrderInfo()[0];
        $weight = $this->getOrderInfo()[1];

        $params = array(
            'ClientInfo'  => array(
                            'AccountCountryCode'    => $reqObject->getAccountCountryCode(),
                            'AccountEntity'         => $reqObject->getAccountEntity(),
                            'AccountNumber'         => $reqObject->getAccountNumber(),
                            'AccountPin'            => $reqObject->getAccountPin(),
                            'UserName'              => $reqObject->getUserName(),
                            'Password'              => $reqObject->getPassword(),
                            'Version'               => 'v1.0'
                        ),
                                    
            'Transaction' => array(
                            'Reference1'            => '001' 
                        ),
                                    
            'OriginAddress' => array(
                            'Line1'                 => 'Originstreet',
                            'City'                  => $reqObject->getOrigCity(),
                            'CountryCode'           => $reqObject->getOrigCountry(),
                        ),
                                    
            'DestinationAddress' => array(
                            'Line1'                 => 'DestinationStree',
                            'City'                  => $reqObject->getDestCity(),
                            'CountryCode'           => $reqObject->getDestCountry(),
                            'PostCode'              => $reqObject->getDestPostal(),
                        ),
            'ShipmentDetails' => array(
                            'PaymentType'            => 'P',
                            'ProductGroup'           => $reqObject->getProductGroup(),
                            'ProductType'            => $reqObject->getProductType(),
                            'ActualWeight'           => array('Value' => $weight, 'Unit' => 'KG'),
                            'ChargeableWeight'       => array('Value' => $weight, 'Unit' => 'KG'),
                            'NumberOfPieces'         => $pices
                        )
        );
            return $params;
    }

    public function sendAramexReq($params){


        if ($this->_helper->getDebugStatus())
            $this->_logger->info(print_r($params,true));

        $client  = $this->_createRateSoapClient();
        $results = $client->CalculateRate($params);

        if ($this->_helper->getDebugStatus())
            $this->_logger->info(print_r($results,true));

        if($results->HasErrors) {
            $this->_result = false;
        }
        else{
            $this->_result = $results->TotalAmount;
        }
        return $this;
    }

    public function getOrderInfo()
    {

        $request = $this->_request;
        $pices   = 0;
        $weight  = 0;

        foreach ($request->getAllItems() as $item) {
            if ($item->getProduct()->isVirtual() || $item->getParentItem()) {
                continue;
            }
            else{
                $pices ++;
                $weight += $item->getWeight()*$item->getQty();
            }
        }

        return array($pices, $weight);
    }

    public function failAramex($result)
    {
        $error = $this->_rateErrorFactory->create();

        $error->setCarrier($this->_code);
        $error->setCarrierTitle($this->getConfigData('title'));
        $error->setErrorMessage($this->getConfigData('specificerrmsg'));

        $result->append($error);

        return $result;
    }

    public function addAramexRate($result, $resultQuote)
     {
         $rate  = $this->_rateMethodFactory->create();
         $price = $resultQuote->Value;

         $rate->setCarrier($this->_code);
         $rate->setMethod($this->_code);

         $rate->setMethodTitle($this->getConfigData('method_title'));
         $rate->setCarrierTitle($this->getConfigData('title'));

         $aramexCurrency = $resultQuote->CurrencyCode;
         $storeCurrency  = $this->directoryHelper->getBaseCurrencyCode();

         $price          = $this->_helper->converCurrency($aramexCurrency,$storeCurrency,$price);
        
         $rate->setCost($price);
         $rate->setPrice($price);
         $result->append($rate);

         return $result;
     }

    public function getAllowedMethods()
    {

    }

    protected function _doShipmentRequest(\Magento\Framework\DataObject $request)
    {
        
    }

}
