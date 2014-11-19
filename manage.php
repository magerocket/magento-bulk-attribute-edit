<?php
set_time_limit(0);
/* 64GB */
ini_set('memory_limit', '65536M');
ini_set('suhosin.memory_limit', '65536M');
define('RUN_CODE', 'admin');
define('RUN_TYPE', 'store');
define('DISABLE_INDEXER', true);
define('AUTO_MODE', true);

if(AUTO_MODE){
    $pathX = explode("/", $_SERVER['REQUEST_URI']);
    $count = count($pathX);
    $path = __FILE__;
    for($i = 0; $i < $count - 1; $i++){
        /* because of paginator */
        if(strpos($pathX[$i], ".php") !== false){
            break;
        }
        $path = dirname($path);
    }

    define('ROOT_FOLDER', $path . DIRECTORY_SEPARATOR);
    if(isset($_SERVER['HTTPS'])){
        define('BASE_URL', 'https://'. $_SERVER['HTTP_HOST']);
    } else {
        define('BASE_URL', 'http://'. $_SERVER['HTTP_HOST']);
    }
} else {
    /** 
     * Before uploading on a new server should change the if(true) to if(false) 
     * and write the appropiate values for magento's root folder and 
     * for the url to this page (whole path till .php)
     */
    define('ROOT_FOLDER', '');
    define('BASE_URL', '');
}

require_once ROOT_FOLDER . 'app' . DIRECTORY_SEPARATOR . 'Mage.php';

class Mage_Shell_Manage extends Mage_ImportExport_Model_Export_Entity_Product
{
    /**
     * Apply filter to collection and add not skipped attributes to select.
     *
     * @param Mage_Eav_Model_Entity_Collection_Abstract $collection
     * @return Mage_Eav_Model_Entity_Collection_Abstract
     */
    protected function _prepareEntityCollection(Mage_Eav_Model_Entity_Collection_Abstract $collection)
    {
        $collection = parent::_prepareEntityCollection($collection);
        if(isset($this->_parameters['export_filter']) && isset($this->_parameters['export_filter']['productIds'])){
            if(is_string($this->_parameters['export_filter']['productIds']))
                $productIds = explode(",", $this->_parameters['export_filter']['productIds']);
            $collection->addIdFilter($productIds, false);
        }
        return $collection;
    }
}

global $app;
$app = Mage::app(RUN_CODE, RUN_TYPE);
Mage::getDesign()->setArea('adminhtml');

$content = $app->getLayout()->createBlock('core/text', 'content_text');

/**
 * Display errors regardless of the request type if we have ?dev=1
 */
if($app->getRequest()->getParam('dev')){
    $content->addText('<h6>Development mode is on </h6>');
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}


/**
 * MANAGE LOGIN AND ACL
 */
$app->getStore()->setConfig(Mage_Core_Model_Cookie::XML_PATH_COOKIE_PATH, "/");
Mage::getSingleton('core/session', array('name'=>'adminhtml'));

if($app->getRequest()->getParam('login', false)){
    $loginData = $app->getRequest()->getParam('login');
    $username = (is_array($loginData) && array_key_exists('username', $loginData)) ? $loginData['username'] : null;
    $user = Mage::getModel('admin/user')->login($username, $loginData['password']);
    if(!$user->getId()){
        Mage::getSingleton('core/session')->addError(Mage::helper('adminhtml')->__('Invalid User Name or Password.'));
        $block = $app   ->getLayout()
                        ->createBlock('adminhtml/template')
                        ->setTemplate('login.phtml');
        echo $block->toHtml();
        exit();
    } else {
        $session = Mage::getModel('admin/session')->renewSession();
        
        /* HACK FOR MAGENTO CE < 1.7.0.0 || EE < 1.12.0.0 || PE */
        $sessionHosts = $session->getSessionHosts();
        $currentCookieDomain = $session->getCookie()->getDomain();
        if (is_array($sessionHosts)) {
            foreach (array_keys($sessionHosts) as $host) {
                // Delete cookies with the same name for parent domains
                if (strpos($currentCookieDomain, $host) > 0) {
                    $session->getCookie()->delete($session->getSessionName(), null, $host);
                }
            }
        }

        if (Mage::getSingleton('adminhtml/url')->useSecretKey()) {
            Mage::getSingleton('adminhtml/url')->renewSecretUrls();
        }
        Mage::getModel('admin/session')->setIsFirstPageAfterLogin(true);
        Mage::getModel('admin/session')->setUser($user);
        Mage::getModel('admin/session')->setAcl(Mage::getResourceModel('admin/acl')->loadAcl());
    }
} else {
    //verify if the user is logged in to the backend
    if(!Mage::getSingleton('admin/session')->isLoggedIn()){
        $block = $app   ->getLayout()
                        ->createBlock('adminhtml/template')
                        ->setTemplate('login.phtml');
        echo $block->toHtml();
        exit();
    }
}
// END OF LOGIN


/**
 * EXPORT
 */
if($app->getRequest()->getParam('show', false) == 'export'){
    if($productIds = Mage::getSingleton('adminhtml/session')->getProductIds()){
        $productIds = implode(",", $productIds);
        /**
         * FROM Mage_ImportExport_Model_Export_Entity_Product => Mage_Shell_Web_Manage
         */
        $model = Mage::getModel('importexport/export');
        $config = Mage::getConfig();
        $config->setNode(
            'global/models/importexport/rewrite/export_entity_product',
            'Mage_Shell_Manage'
        );
        $model->setData('entity', 'catalog_product');
        $model->setData('file_format', 'csv');
        $model->setData('frontend_label', '');
        $model->setData('attribute_code', '');
        $model->setData('export_filter', array('productIds' => $productIds));
        
        _prepareDownloadResponse($model->getFileName(),
                    $model->export(),
                    $model->getContentType());
        $app->getResponse()->sendResponse();
        exit;
    }
}


function _prepareDownloadResponse($fileName, $content, $contentType = 'application/octet-stream', $contentLength = null)
{
    global $app;

    $isFile = false;
    $file   = null;
    if (is_array($content)) {
        if (!isset($content['type']) || !isset($content['value'])) {
            return $app;
        }
        if ($content['type'] == 'filename') {
            $isFile         = true;
            $file           = $content['value'];
            $contentLength  = filesize($file);
        }
    }

    $app->getResponse()
        ->setHttpResponseCode(200)
        ->setHeader('Pragma', 'public', true)
        ->setHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0', true)
        ->setHeader('Content-type', $contentType, true)
        ->setHeader('Content-Length', is_null($contentLength) ? strlen($content) : $contentLength, true)
        ->setHeader('Content-Disposition', 'attachment; filename="'.$fileName.'"', true)
        ->setHeader('Last-Modified', date('r'), true);

    if (!is_null($content)) {
        if ($isFile) {
            $app->getResponse()->clearBody();
            $app->getResponse()->sendHeaders();

            $ioAdapter = new Varien_Io_File();
            $ioAdapter->open(array('path' => $ioAdapter->dirname($file)));
            $ioAdapter->streamOpen($file, 'r');
            while ($buffer = $ioAdapter->streamRead()) {
                print $buffer;
            }
            $ioAdapter->streamClose();
            if (!empty($content['rm'])) {
                $ioAdapter->rm($file);
            }

            exit(0);
        } else {
            $app->getResponse()->setBody($content);
        }
    }
    return $app;
}
// END OF EXPORT

/**
 * @todo THE GUI
 * After this point we will display a 2columns-left page with the blocks: page [head, left, content]
 */
global $scriptBaseUrl;
if(AUTO_MODE){
    $scriptBaseUrl = BASE_URL . $app->getRequest()->getBaseUrl();
    define('JS_BASE_URL', $scriptBaseUrl);
} else {
    $scriptBaseUrl = BASE_URL . "?" . $_SERVER['QUERY_STRING'];
    define('JS_BASE_URL', BASE_URL);
}


/**
 * AJAX - most of the requests handled by an "controller"
 * NON AJAX - first request after login, since the login is taken care above
 */
if($app->getRequest()->isAjax()){
    //getting the request
    ajaxController();
    //rendering the response - updating the content block
    $app->getResponse()->sendResponse();
    exit;
} else {
 
    /**
     * Page block generates the placeholders for the following blocks
     * Head block generates the html between tags <head> ... </head>
     * Content block generates the html  between tags <body> ... </body>
     * content [left [store_switcher, category_tree], main[attributes_form || products grid]]
     */
    $params = $app->getRequest()->getParams();
    unset($params['store']);
    $text = $app->getLayout()->createBlock('core/text')->setText(
        '<script type="text/javascript">
            var BASE_URL = \''. JS_BASE_URL .'\';
            var QUERY_PARAMS_WITHOUT_STORE = \''. http_build_query($params) .'\';
        </script>'
    );
    
    /**
     * Using addJs, addItem, addCss to add what we need from adminhtml/layout/main.xml <default>
     */
    $head = $app    ->getLayout()
                    ->createBlock('adminhtml/page_head', 'head')
                    ->addJs('prototype/prototype.js')
                    ->addItem('js', 'extjs/fix-defer-before.js')
                    ->addJs('prototype/window.js')
                    ->addJs('scriptaculous/effects.js')
                    ->addJs('prototype/validation.js')
                    ->addJs('varien/js.js') 
                    ->addJs('mage/translate.js')
                    ->addJs('mage/adminhtml/events.js')
                    ->addJs('mage/adminhtml/loader.js')
                    ->addJs('mage/adminhtml/accordion.js')
                    ->addJs('mage/adminhtml/tools.js')
                    ->addJs('mage/adminhtml/grid.js')
                    ->addJs('mage/adminhtml/hash.js')
                    ->addJs('mage/adminhtml/tabs.js')
                    ->addJs('mage/adminhtml/form.js')
                    ->addJs('mage/adminhtml/product.js')
                    ->addJs('extjs/ext-tree.js')
                    ->addJs('extjs/fix-defer.js')
                    ->addJs('extjs/ext-tree-checkbox.js')
                    ->addCss('reset.css')
                    ->addCss('boxes.css')
                    ->addCss('custom.css')
                    ->addItem('skin_css', 'iestyles.css')
                    ->addItem('skin_css', 'below_ie7.css')
                    ->addItem('skin_css', 'ie7.css')
                    ->addItem('js_css', 'calendar/calendar-win2k-1.css')
                    ->addItem('js', 'calendar/calendar.js')
                    ->addItem('js', 'calendar/calendar-setup.js')
                    ->addItem('js_css', 'extjs/resources/css/ext-all.css')
                    ->addItem('js_css', 'extjs/resources/css/ytheme-magento.css')
                    ->setTemplate('page/head.phtml');
    
    /**
     * GETTING BLOCKS FOR ALL THE STUFF WE NEED
     * 1 Categories
     * 2 Attributes - through AJAX
     */
    $switcher = $app->getLayout()
                    ->createBlock('adminhtml/store_switcher');
    getCategoryTree();
    $left = $app    ->getLayout()
                    ->createBlock('core/text');
    $left->addText($text->getText());
    $left->addText($switcher->toHtml());
    $left->addText(getTreeHtml());
    $left->addText(getTreeJs());
    
    $page = $app    ->getLayout()
                    ->createBlock('adminhtml/page')
                    ->setChild($text)
                    ->unsetChild('header')
                    ->unsetChild('menu')
                    ->setChild('head', $head)
                    ->setChild('left', $left);
    
    
    $page   ->setChild('content', getContentHtml())
            ->setTemplate('page.phtml');
    
    echo($page->toHtml());
    exit;
}


function ajaxController()
{
    global $app;
    $show = $app->getRequest()->getParam('show', false);
    
    if(!$show){
        /**
         * PAGINATOR FOR THE PRODUCTS GRID
         */
        if(strpos($app->getRequest()->getPathInfo(), "show/paginator")!==false){
            if(strpos($app->getRequest()->getPathInfo(), ".php")!==false){
                $params = explode("/", trim($app->getRequest()->getPathInfo(),"/"));
                foreach($params as $key => $param){
                    unset($params[$key]);
                    if(strpos($param, ".php")!==false){
                        break;
                    }
                }
            } else {
                $params = explode("/", trim($app->getRequest()->getPathInfo(),"/"));
            }
            $p = array();
            foreach($params as $key => $param){
                if(isset($previous)){
                    $p[$previous] = $param;
                    unset($previous);
                } else {
                    $previous = $param;
                    $p[$previous] = null;
                }
            }
            //not JSON
            $content = getProductsGridHtml($p);
            $app->getResponse()->setBody($content);
            return;
        }
        // END OF PAGINATOR
    }
    
    switch ($show){        
        case 'attributes':
            $products = _initAttributes();
            $content = Mage::helper('core')->jsonEncode(
                    array(
                        'content' => getAttributesHtml($products) . disableFormElementsJs() . updateInputs(),
                        'messages' => ''
                    )
                );
            break;
        
        case 'move-category':
            $content = categoryMove();
            break;
        
        case 'update':
            $content = Mage::helper('core')->jsonEncode(
                array(
                    'content' => saveProducts(),
                    'messages' => ''
                )
            );
            break;
        
        case 'review':
            $content = Mage::helper('core')->jsonEncode(
                    array(
                        'content' => getProductsGridHtml() . updateInputs(),
                        'message' => 'success'
                    )
                );
            break;
               
        default:
            $content = getCategoryTree();
            break;
    }
    
    $app->getResponse()->setBody($content);
    return;
}


function _initCategory()
{
    global $app;
    $categoryId = (int) $app->getRequest()->getParam('id', false);
    $storeId    = (int) $app->getRequest()->getParam('store', 0);
    
    $category = Mage::getModel('catalog/category');
    $category->setStoreId($storeId);
    
    if ($categoryId) {
        $category->load($categoryId);
        if ($storeId) {
            $rootId = $app->getStore($storeId)->getRootCategoryId();
            if (!in_array($rootId, $category->getPathIds())) {
                $category->load($rootId);
            }
        }
    }

    Mage::register('category', $category);
    Mage::register('current_category', $category);
    return $category;
}


function _initAttributes()
{
    global $app;
    $storeId    = (int) $app->getRequest()->getParam('store', 0);
    $category = _initCategory();
    //string so we need an explode
    $children = $category->getAllChildren();
    
    /**
     * FROM Mage_Adminhtml_Helper_Catalog_Product_Edit_Action_Attribute
     * getProducts()
     * getAttributes()
     */
    $products = Mage::getResourceModel('catalog/product_collection')
                ->setStoreId($storeId)
//                ->addCategoryFilter($category)
                ->joinField('category_id', 'catalog/category_product' , 'category_id' , 'product_id=entity_id' , null , 'left')
                ->addAttributeToFilter('category_id', array('in' => explode(",", $children)));
    //We need unique ids
    $products->getSelect()->group('e.entity_id');
    $products->load();
    $productIds = $products->getLoadedIds();
    Mage::getSingleton('adminhtml/session')->unsetData('product_ids');
    Mage::getSingleton('adminhtml/session')->setProductIds($productIds);
    return $products;
}


/**
 * FROM Mage_Adminhtml_Block_Catalog_Product_Edit_Action_Attribute_Tab_Attributes
 * FROM Mage_Adminhtml_Block_Widget_Form
 * @param type $app
 * @return type
 */
function getAttributesHtml()
{
    Mage::getSingleton('adminhtml/session')->unsetData('inventory_data');
    Mage::getSingleton('adminhtml/session')->unsetData('attributes_data');
    Mage::getSingleton('adminhtml/session')->unsetData('website_remove_data');
    Mage::getSingleton('adminhtml/session')->unsetData('website_add_data');
    
    return getTabsHtmlAndJs();
}


/**
 * FROM Mage_Adminhtml_Catalog_CategoryController - moveAction()
 * @param int $categoryId
 * @param Mage_Core_Model_App $app
 * @param int $storeId
 * @return string
 */
function categoryMove()
{
    global $app;
    $category = _initCategory();
    if (!$category) {
        $app->getResponse()->setBody(Mage::helper('catalog')->__('Category move error'));
        return;
    }
    $parentNodeId   = $app->getRequest()->getPost('pid', false);
    /**
     * Category id after which we have put our category
     */
    $prevNodeId     = $app->getRequest()->getPost('aid', false);
    $category->setData('save_rewrites_history', Mage::helper('catalog')->shouldSaveUrlRewritesHistory());
    try {
        $category->move($parentNodeId, $prevNodeId);
        return "SUCCESS";
    }
    catch (Mage_Core_Exception $e) {
        return $e->getMessage();
    }
    catch (Exception $e){
        return Mage::helper('catalog')->__('Category move error %s', $e);
        Mage::logException($e);
    }
}


/**
 * By default the form elements should be disabled
 * @return string
 */
function disableFormElementsJs()
{
    return '<script type="text/javascript">
    //<![CDATA[
        $$(\'input[type!="checkbox"],textarea,select\', \'attributes_edit_form\').each( function(item) {
            disableFieldEditMode(item);
        });
        if($(\'store_switcher\')){
            $(\'store_switcher\').enable();
        }
    //]]>
    </script>';
}

/**
 * We add these fields to the form
 * @return string
 */
function updateInputs()
{
    if(!($category = Mage::registry('current_category'))){
        $category = _initCategory();
    }
    $categoryId = $category->getId();
    return '<script type="text/javascript">
    //<![CDATA[
        if(document.getElementById(\'categoryId\') === null){
            document.getElementById(\'attributes_edit_form\').innerHTML += "<input type=\'hidden\' id=\'show\' name=\'show\' value=\'review\'/>";
            document.getElementById(\'attributes_edit_form\').innerHTML += "<input type=\'hidden\' id=\'categoryId\' name=\'id\' value=\''.$categoryId.'\'/>";
        } else {
            document.getElementById(\'show\').value = \'review\';
            document.getElementById(\'categoryId\').value = \''.$categoryId.'\';
        }
        
    //]]>
    </script>';
}


/**
 * Rendering the grid with the attributes modified (just for view-ing)
 * @return string
 */
function getProductsGridHtml($params = null)
{
    global $app;
    // FROM Mage_Adminhtml_Catalog_Product_Action_AttributeController::saveAction()
    if(!isset($params)){
        $inventoryData      = $app->getRequest()->getParam('inventory', array());
        $attributesData     = $app->getRequest()->getParam('attributes', array());
        $websiteRemoveData  = $app->getRequest()->getParam('remove_website_ids', array());
        $websiteAddData     = $app->getRequest()->getParam('add_website_ids', array());

        foreach (Mage::helper('cataloginventory')->getConfigItemOptions() as $option) {
            if (isset($inventoryData[$option]) && !isset($inventoryData['use_config_' . $option])) {
                $inventoryData['use_config_' . $option] = 0;
            }
        }
        
        $products = _initAttributes();
        $productIds = $products->getLoadedIds();
        if(is_string($productIds))
            $productIds = explode(",", $productIds);

        Mage::getSingleton('adminhtml/session')->setInventoryData($inventoryData);
        Mage::getSingleton('adminhtml/session')->setAttributesData($attributesData);
        Mage::getSingleton('adminhtml/session')->setWebsiteRemoveData($websiteRemoveData);
        Mage::getSingleton('adminhtml/session')->setWebsiteAddData($websiteAddData);
        Mage::getSingleton('adminhtml/session')->setProductIds($productIds);
        
    } else {
        //TO WORK WITH PAGINATOR
        $inventoryData      = Mage::getSingleton('adminhtml/session')->getInventoryData();
        $attributesData     = Mage::getSingleton('adminhtml/session')->getAttributesData();
        $websiteRemoveData  = Mage::getSingleton('adminhtml/session')->getWebsiteRemoveData();
        $websiteAddData     = Mage::getSingleton('adminhtml/session')->getWebsiteAddData();
        $productIds = Mage::getSingleton('adminhtml/session')->getProductIds();
    }
    
    if(is_string($productIds))
        $productIds = explode(",", $productIds);
    $collection = Mage::getModel('catalog/product') ->getCollection()
                                                    ->addIdFilter($productIds, false);
    $grid = $app->getLayout()->createBlock('adminhtml/widget_grid');
    $grid->setData('id', 'grid');
    //        $grid->setHeadersVisibility(false);
    $grid->addColumn('entity_id',
            array(
                'header'=> Mage::helper('catalog')->__('ID'),
                'width' => '50px',
                'type'  => 'number',
                'index' => 'entity_id',
        ));
    
    if ($attributesData) {
        $dateFormat = Mage::app()->getLocale()->getDateFormat(Mage_Core_Model_Locale::FORMAT_TYPE_SHORT);
        
        foreach ($attributesData as $attributeCode => $value) {
            $attribute = Mage::getSingleton('eav/config')
                ->getAttribute(Mage_Catalog_Model_Product::ENTITY, $attributeCode);
            if (!$attribute->getAttributeId()) {
                unset($attributesData[$attributeCode]);
                continue;
            }
            if ($attribute->getBackendType() == 'datetime') {
                if (!empty($value)) {
                    $filterInput    = new Zend_Filter_LocalizedToNormalized(array(
                        'date_format' => $dateFormat
                    ));
                    $filterInternal = new Zend_Filter_NormalizedToLocalized(array(
                        'date_format' => Varien_Date::DATE_INTERNAL_FORMAT
                    ));
                    $value = $filterInternal->filter($filterInput->filter($value));
                } else {
                    $value = null;
                }
                $attributesData[$attributeCode] = $value;
            } elseif ($attribute->getFrontendInput() == 'multiselect') {
                // Check if 'Change' checkbox has been checked by admin for this attribute
                $isChanged = (bool)$app->getRequest()->getPost($attributeCode . '_checkbox');
                if (!$isChanged) {
                    unset($attributesData[$attributeCode]);
                    continue;
                }
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                $attributesData[$attributeCode] = $value;
            }
            /**
             * FOR REVIEW
             */
            if($attribute->getFrontendInput() == 'text' || $attribute->getFrontendInput() == 'textarea'){
                $attributesText[] = $attributeCode;
            }
            
            $collection->addAttributeToSelect($attributeCode);
            $grid->addColumn($attributeCode,
                array(
                    'header'=> $attribute->getFrontendLabel(),
                    'index' => $attributeCode,
            ));
        }        
    }
    
    if ($inventoryData) {
        $collection->joinField('qty',
                'cataloginventory/stock_item',
                'qty',
                'product_id=entity_id',
                '{{table}}.stock_id=1',
                'left');
        foreach($inventoryData as $key => $data){
            $collection->addAttributeToSelect($key);
            $grid->addColumn($key,
                array(
                    'header'=> ucfirst($key),
                    'index' => $key,
            ));
        }
    }
    
    if ($websiteRemoveData || $websiteAddData) {
        $collection->joinField('websites',
                    'catalog/product_website',
                    'website_id',
                    'product_id=entity_id',
                    null,
                    'left');
        $grid->addColumn('websites',
                array(
                    'header'=> Mage::helper('catalog')->__('Websites'),
                    'width' => '100px',
                    'sortable'  => false,
                    'index'     => 'websites',
                    'type'      => 'options',
                    'options'   => Mage::getModel('core/website')->getCollection()->toOptionHash(),
            ));
    }
    
    $defaultParams = array( 
                                $grid->getVarNamePage() => 1, 
                                $grid->getVarNameSort() => null, //dir must be after sort - to set collection->setOrder(sort, dir);
                                $grid->getVarNameDir() => 'desc', 
                                $grid->getVarNameFilter() => array(), 
                                $grid->getVarNameLimit() => 20
                        );

    if(isset($params)){
        foreach($params as $key => $param){
            Mage::getSingleton('adminhtml/session')->setData($grid->getId() . $key, $param);
        }
    } else {
        //When we first see the grid should clear session params
        foreach($defaultParams as $key => $param){
            Mage::getSingleton('adminhtml/session')->unsetData($grid->getId() . $key);
        }
    }

    foreach($defaultParams as $key => $param){
        if(isset($params[$key])){
            $param = $params[$key];
        } else if(Mage::getSingleton('adminhtml/session')->getData($grid->getId() . $key)){
            $param = Mage::getSingleton('adminhtml/session')->getData($grid->getId() . $key);
        }
        if($key == $grid->getVarNameSort()){
            $sort = $param;
        }
        if($key == $grid->getVarNameDir()){
            $param = array($sort, $param);
        }
        $collection = addCollectionFilters($collection, $key, $param);
    }
    
    /**
     * sort of like Mage_Adminhtml_Block_Catalog_Product_Grid
     * we replace {{current}}
     */
    foreach($collection as $item){
       foreach($attributesData as $attributeCode => $value){
           if(in_array($attributeCode, $attributesText)){
               $info = $item->getData($attributeCode);
               $data = str_replace("{{current}}", $info, $value);
           } else {
               $data = $value;
           }
           $item->setData($attributeCode, $data);
       }
       foreach($inventoryData as $attributeCode => $value){;
           $item->setData($attributeCode, $value);
       }
        $item->setData('websites', $websiteAddData);
    }

    $grid->setSaveParametersInSession(true);
    $grid->setUseAjax(true);
    $grid->setCollection($collection);        

    /**
    * These two blocks change their grid ids if we let _prepareLayout set them and they are useless.
    */
    $grid->setChild('reset_filter_button',
       $app->getLayout()->createBlock('adminhtml/widget_button')
           ->setData(array(
               'label'     => Mage::helper('adminhtml')->__('Reset Filter'),
               'onclick'   => $grid->getJsObjectName().'.resetFilter()',
           ))
    );
    $grid->setChild('search_button',
       $app->getLayout()->createBlock('adminhtml/widget_button')
           ->setData(array(
               'label'     => Mage::helper('adminhtml')->__('Search'),
               'onclick'   => $grid->getJsObjectName().'.doFilter()',
               'class'   => 'task'
           ))
    );

    if(isset($params)){
       return $grid->toHtml();
    } else {
       return $grid->toHtml() . getGridJs($grid);
    }

    echo Mage::helper('core')->jsonEncode(
            array(
                'content' => 'No attribute was changed!',
                'message' => 'error'
                )
            );
    exit;
}


function addCollectionFilters($collection, $key, $param)
{
    switch($key){
        case 'limit':
            $collection->setPageSize($param);
            break;
        case 'page':
            $collection->setCurPage($param);
            break;
        case 'dir':
        case 'sort':
            $collection->setOrder($param[0], strtoupper($param[1]));
            break;
        case 'filter':
            $filters = Mage::helper('adminhtml')->prepareFilterString($param);
            if(!empty($filters)){
                foreach($filters as $key => $filter){
                    if(is_array($filter) && array_key_exists('from', $filter)){
                        $collection->addFieldToFilter($key, array('from' => $filter['from'], 'to' => $filter['to']));
                    } else {
                        $collection->addFieldToFilter($key, array('like' => '%' . $filter . '%'));
                    }
                }
            }
            break;
    }
    
    return $collection;
}


function getGridJs($grid)
{
    return '<script type="text/javascript">
                '.$grid->getJsObjectName().'.url = BASE_URL + "/show/paginator/";
            </script>';
}


function getContentHtml()
{
    global $app;
    $content = $app->getLayout()->getBlock('content_text');
    $content->addText('<div id="category-edit-container" class="category-content"></div>');
    /**
     * Adding tabs for the attributes - like mass update page
     */
    $content->addText(getTabsHtmlAndJs());
    $content->addText(getFormHtml());
    $content->addText(getFormJs());
    
    return $content;
}


function getTabsHtmlAndJs()
{
    global $app;
    /**
     * FROM Mage_Adminhtml_Block_Catalog_Product_Edit_Action_Attribute_Tabs
     * FROM adminhtml/layout/catalog.xml
     */
    //productIds are set in renderAttributesDetails
    $productsIds = Mage::getSingleton('adminhtml/session')->getProductIds();
    $show = $app->getRequest()->getParam('show', false);
    if($productsIds && $show){
        $tabs = $app->getLayout()->createBlock('adminhtml/catalog_product_edit_action_attribute_tabs');
        
        $tab_attributes = $app->getLayout()->createBlock('adminhtml/catalog_product_edit_action_attribute_tab_attributes');
        $tab_inventory = $app->getLayout()->createBlock('adminhtml/catalog_product_edit_action_attribute_tab_inventory')
                ->setTemplate('catalog/product/edit/action/inventory.phtml');
        $tab_website = $app->getLayout()->createBlock('adminhtml/catalog_product_edit_action_attribute_tab_websites')
                ->setTemplate('catalog/product/edit/action/websites.phtml');
        $tabs->addTab('attributes', $tab_attributes);
        $tabs->addTab('inventory', $tab_inventory);
        $tabs->addTab('websites', $tab_website);
        $js = '
        <script type="text/javascript">
            attributes_update_tabsJsTabs = new varienTabs(
                    \'attributes_update_tabs\', 
                    \'attributes_edit_form\', 
                    \'attributes_update_tabs_attributes\', 
                    []
            );
        </script>';
        return $tabs->toHtml() . $js;
    }
    return '';
}


/**
 * Displayed after we click on a category
 * @return string
 */
function getFormHtml()
{
    global $scriptBaseUrl, $app;
    
    $saveButton = $app->getLayout()->createBlock('adminhtml/widget_button')
                ->setData(array(
                    'label'     => Mage::helper('catalog')->__('Save'),
                    'onclick'   => 'attributesForm.updateProducts()',
                    'class'     => 'save'
                ));
    
    /**
     * TOP FORM
     */
    $form = $app->getLayout()   ->createBlock('adminhtml/catalog_product_edit_action_attribute')
                                ->setChild('save_button', $saveButton)
                                ->unsetChild('reset_button')
                                ->unsetChild('back_button');

    return 
    '<div class="content-header" id="content-header-tabs" style="display:none">
        <table cellspacing="0">
            <tr>
                <td><h3>'. Mage::helper('catalog')->__('Update attributes') .'</h3></td>
                <td class="form-buttons">'.
                $form->getButtonHtml(Mage::helper('catalog')->__('Export CSV'), 'attributesForm.export()', 'export', 'export').
                $form->getButtonHtml(Mage::helper('catalog')->__('Review'), 'attributesForm.review()', 'review', 'review').
                $form->getSaveButtonHtml().
                '</td>
            </tr>
        </table>
    </div>
    <form action="'. $scriptBaseUrl .'" method="post" id="attributes_edit_form" enctype="multipart/form-data">'. $form->getBlockHtml('formkey').'</form>';
}


function getFormJs()
{
    return
        '<script type="text/javascript">
            var attributesForm = new varienForm(\'attributes_edit_form\', BASE_URL);
            attributesForm._processValidationResult = function(transport) {
                var response = transport.responseText.evalJSON();

                if (response.error){
                    if (response.attribute && $(response.attribute)) {
                        $(response.attribute).setHasError(true, attributesForm);
                        Validation.ajaxError($(response.attribute), response.message);
                        if (!Prototype.Browser.IE){
                            $(response.attribute).focus();
                        }
                    } else if ($(\'messages\')) {
                        $(\'messages\').innerHTML = \'<ul class="messages"><li class="error-msg"><ul><li>\' + response.message + \'</li></ul></li></ul>\';
                    }
                } else {
                    attributesForm._submit();
                }
            };
            
            attributesForm.updateProducts = function(show){
                document.getElementById(\'show\').value = "update";
                document.getElementById(this.formId).removeChild(document.getElementById(\'categoryId\'));
                if(this.validator && this.validator.validate()){
                    var url = this.validationUrl;
                    new Ajax.Request(
                        url + (url.match(new RegExp(\'\\\\?\')) ? \'&isAjax=true\' : \'?isAjax=true\' ),
                        {
                            parameters:  document.getElementById(this.formId).serialize(true),
                            method:      \'post\',
                            onComplete : function(transport) {
                                response = transport.responseText.evalJSON();
                                if (!response) {
                                    return false;
                                }
                                if(response.message != "error"){
                                    $(\'attributes_update_tabs\').style.display = "none";
                                    $(\'export\').style.display = "none";
                                    $(\'review\').style.display = "none";
                                    document.getElementById(\'attributes_edit_form\').update(response.content);
                                    $(\'messages\').innerHTML = \'\';
                                } else if ($(\'messages\')) {
                                    $(\'messages\').innerHTML = \'<ul class="messages"><li class="error-msg"><ul><li>\' + response.content + \'</li></ul></li></ul>\';
                                }
                            }
                        }
                    );
                }
                return false;
            }
            
            attributesForm.review = function(){
                document.getElementById(\'show\').value = "review";
                if(this.validator && this.validator.validate()){
                    var url = this.validationUrl;
                    new Ajax.Request(
                        url + (url.match(new RegExp(\'\\\\?\')) ? \'&isAjax=true\' : \'?isAjax=true\' ),
                        {
                            parameters:  document.getElementById(this.formId).serialize(true),
                            method:      \'post\',
                            onComplete : function(transport) {
                                response = transport.responseText.evalJSON();
                                if (!response) {
                                    return false;
                                }
                                if(response.message != "error"){
                                    $(\'attributes_update_tabs\').style.display = "none";
                                    $(\'export\').style.display = "none";
                                    $(\'review\').style.display = "none";
                                    document.getElementById(\'attributes_edit_form\').update(response.content);
                                    $(\'messages\').innerHTML = \'\';
                                } else if ($(\'messages\')) {
                                    $(\'messages\').innerHTML = \'<ul class="messages"><li class="error-msg"><ul><li>\' + response.content + \'</li></ul></li></ul>\';
                                }
                            }
                        }
                    );
                }
                return false;
            }
            
            attributesForm.export = function(){
                if(this.validator && this.validator.validate()){
                    var url = this.validationUrl;
                    url += (url.match(new RegExp(\'\\\\?\')) ? \'&show=export\' : \'?show=export\' );
//                    url += \'&productIds=\'+document.getElementById(\'productIds\').value;
                    setLocation(url);
                    return false;
                }
            }
            
            
            <!-- disable form elements -->
            $$(\'input[type!="checkbox"],textarea,select\', \'attributes_edit_form\').each( function(item) {
                disableFieldEditMode(item);
            });
            if($(\'store_switcher\')){
                $(\'store_switcher\').enable();
            }
        </script>';
}


function getCategoryTree()
{
    global $app;
    $category = _initCategory();
    /**
     * Categories tree with changes to work alone - because of js and url we can't use the block from admin, 
     * but we can reuse code from it
     */
    $tree = $app    ->getLayout()
                    ->createBlock('adminhtml/catalog_category_tree', 'categories_tree')
                    ->unsetChild('add_root_button')
                    ->unsetChild('add_sub_button')
                    ->unsetChild('store_switcher')
                    ->unsetChild('expand_button')
                    ->unsetChild('collapse_button');
    
    return $tree->getTreeJson($category);
}


function getTreeHtml()
{
    return 
        '<div class="categories-side-col">
            <div class="content-header">
                <h3 class="icon-head head-categories">'. Mage::helper('catalog')->__('Categories') .'</h3>
            </div>
            <div class="tree-holder">
                <div id="tree-div" style="width:100%; overflow:auto;"></div>
            </div>
        </div>';
}


function getTreeJs()
{
    global $scriptBaseUrl, $app;
    $tree = $app->getLayout()->getBlock('categories_tree');
    return 
        '<script type="text/javascript">
        //<![CDATA[
            var tree;

            /**
             * Fix ext compatibility with prototype 1.6
             */
            Ext.lib.Event.getTarget = function(e) {
                var ee = e.browserEvent || e;
                return ee.target ? Event.element(ee) : null;
            };

            Ext.tree.TreePanel.Enhanced = function(el, config)
            {
                Ext.tree.TreePanel.Enhanced.superclass.constructor.call(this, el, config);
            };

            Ext.extend(Ext.tree.TreePanel.Enhanced, Ext.tree.TreePanel, {

                loadTree : function(config, firstLoad)
                {
                    var parameters = config[\'parameters\'];
                    var data = config[\'data\'];

                    this.storeId = parameters[\'store_id\'];

                    if ( this.storeId != 0 && $(\'add_root_category_button\')) {
                        $(\'add_root_category_button\').hide();
                    }

                    if ((typeof parameters[\'root_visible\']) != \'undefined\') {
                        this.rootVisible = parameters[\'root_visible\']*1;
                    }

                    var root = new Ext.tree.TreeNode(parameters);

                    this.nodeHash = {};
                    this.setRootNode(root);

                    if (firstLoad) {
                        this.addListener(\'click\', this.categoryClick);
                        this.addListener(\'beforenodedrop\', categoryMove.createDelegate(this));
                    }

                    this.loader.buildCategoryTree(root, data);
                    this.el.dom.innerHTML = \'\';
                    // render the tree
                    this.render();
                    if (parameters[\'expanded\']) {
                        this.expandAll();
                    } else {
                        root.expand();
                    }

                    var selectedNode = this.getNodeById(parameters[\'category_id\']);
                    if (selectedNode) {
                        this.currentNodeId = parameters[\'category_id\'];
                    }
                    this.selectCurrentNode();
                },

                request : function(url, params)
                {
                    if (!params) {
                        if (category_info_tabsJsTabs.activeTab) {
                            var params = {active_tab_id:category_info_tabsJsTabs.activeTab.id};
                        }
                        else {
                            var params = {};
                        }
                    }
                    if (!params.form_key) {
                        params.form_key = FORM_KEY;
                    }

                    var result = new Ajax.Request(
                        url + (url.match(new RegExp(\'\\\\?\')) ? \'&isAjax=true\' : \'?isAjax=true\' ),
                        {
                           parameters:  params,
                           method:      \'post\'
                        }
                    );

                    return result;
                },

                selectCurrentNode : function()
                {
                    if (this.currentNodeId) {
                        var selectedNode = this.getNodeById(this.currentNodeId);
                        if ((typeof selectedNode.attributes.path)!=\'undefined\') {
                            var path = selectedNode.attributes.path;
                            if (!this.storeId) {
                                path = \'0/\'+path;
                            }
                            this.selectPath(path);
                        } else {
                            this.getSelectionModel().select(selectedNode);
                        }
                    }
                },

                collapseTree : function()
                {
                    this.collapseAll();

                    this.selectCurrentNode();

                    if (!this.collapsed) {
                        this.collapsed = true;
                        this.loader.dataUrl = "'. $scriptBaseUrl .'";
                        this.request(this.loader.dataUrl, false);
                    }
                },

                expandTree : function()
                {
                    this.expandAll();
                    if (this.collapsed) {
                        this.collapsed = false;
                        this.loader.dataUrl = "'. $scriptBaseUrl .'";
                        this.request(this.loader.dataUrl, false);
                    }
                },

                categoryClick : function(node, e)
                {
                    var baseUrl = "'. $scriptBaseUrl .'";

                    this.currentNodeId = node.id;

                    if (!this.useAjax) {
                        setLocation(url);
                        return;
                    }

                    var params = {store: this.storeId, form_key: FORM_KEY, id: node.id, show: \'attributes\'}
                    document.getElementById(\'content-header-tabs\').style.display="block";
                    $(\'attributes_edit_form\').update(\'\');
                    updateContent(baseUrl, params);
                    if($(\'export\')){
                        $(\'export\').style.display = "inline";
                        $(\'review\').style.display = "inline";                    
                    }
                }
            });

            function reRenderTree(event, switcher)
            {
                // re-render tree by store switcher
                if (tree && event) {
                    var obj = event.target;
                    var newStoreId = obj.value * 1;
                    var storeParam = newStoreId ? \'store/\'+newStoreId + \'/\' : \'\';

                    if (obj.switchParams) {
                        storeParam += obj.switchParams;
                    }
                    if (switcher.useConfirm) {
                        if (!confirm("'. Mage::helper('catalog')->__('Please confirm site switching. All data that hasn\'t been saved will be lost.') .'")){
                            obj.value = "'. (int) $tree->getStoreId() .'";
                            return false;
                        }
                    }

                    if ($(\'add_root_category_button\')) {
                        if (newStoreId == 0) {
                            $(\'add_root_category_button\').show();
                        }
                        else {
                            $(\'add_root_category_button\').hide();
                        }
                    }

                    // retain current selected category id
                    storeParam = storeParam + \'id/\' + tree.currentNodeId + \'/\';
                    var url = tree.switchTreeUrl + storeParam;

                    // load from cache
                    // load from ajax
                    new Ajax.Request(url + (url.match(new RegExp(\'\\\\?\')) ? \'&isAjax=true\' : \'?isAjax=true\' ), {
                        parameters : {store: newStoreId, form_key: FORM_KEY},
                        method     : \'post\',
                        onComplete : function(transport) {
                            var response = eval(\'(\' + transport.responseText + \')\');
                            if (!response[\'parameters\']) {
                                return false;
                            }

                            _renderNewTree(response, storeParam);
                        }
                    });
                }
                // render default tree
                else {
                    _renderNewTree();
                }
            }

            function _renderNewTree(config, storeParam)
            {
                if (!config) {
                    var config = defaultLoadTreeParams;
                }
                if (tree) {
                    tree.purgeListeners();
                    tree.el.dom.innerHTML = \'\';
                }
                tree = new Ext.tree.TreePanel.Enhanced(\'tree-div\', newTreeParams);
                tree.loadTree(config, true);

                // try to select current category
                var selectedNode = tree.getNodeById(config.parameters.category_id);
                if (selectedNode) {
                    tree.currentNodeId = config.parameters.category_id;
                }
                tree.selectCurrentNode();

                // update content area
                var url = tree.editUrl;
                if (storeParam) {
                    url = url + storeParam;
                }

                updateContent(url);
            }

            Ext.onReady(function()
            {
                categoryLoader = new Ext.tree.TreeLoader({
                   dataUrl: "'. $scriptBaseUrl .'"
                });

                categoryLoader.createNode = function(config) {
                    var node;
                    var _node = Object.clone(config);
                    if (config.children && !config.children.length) {
                        delete(config.children);
                        node = new Ext.tree.AsyncTreeNode(config);
                    } else {
                        node = new Ext.tree.TreeNode(config);
                    }

                    return node;
                };

                categoryLoader.buildCategoryTree = function(parent, config)
                {
                    if (!config) return null;

                    if (parent && config && config.length){
                        for (var i = 0; i < config.length; i++) {
                            var node;
                            var _node = Object.clone(config[i]);
                            if (_node.children && !_node.children.length) {
                                delete(_node.children);
                                node = new Ext.tree.AsyncTreeNode(_node);
                            } else {
                                node = new Ext.tree.TreeNode(config[i]);
                            }
                            parent.appendChild(node);
                            node.loader = node.getOwnerTree().loader;
                            if (_node.children) {
                                this.buildCategoryTree(node, _node.children);
                            }
                        }
                    }
                };

                categoryLoader.buildHash = function(node)
                {
                    var hash = {};

                    hash = this.toArray(node.attributes);

                    if (node.childNodes.length>0 || (node.loaded==false && node.loading==false)) {
                        hash[\'children\'] = new Array;

                        for (var i = 0, len = node.childNodes.length; i < len; i++) {
                            if (!hash[\'children\']) {
                                hash[\'children\'] = new Array;
                            }
                            hash[\'children\'].push(this.buildHash(node.childNodes[i]));
                        }
                    }

                    return hash;
                };

                categoryLoader.toArray = function(attributes) {
                    var data = {form_key: FORM_KEY};
                    for (var key in attributes) {
                        var value = attributes[key];
                        data[key] = value;
                    }

                    return data;
                };

                categoryLoader.on("beforeload", function(treeLoader, node) {
                    treeLoader.baseParams.id = node.attributes.id;
                    treeLoader.baseParams.store = node.attributes.store;
                    treeLoader.baseParams.form_key = FORM_KEY;
                });

                categoryLoader.on("load", function(treeLoader, node, config) {
                    varienWindowOnload();
                });

                newTreeParams = {
                    animate         : false,
                    loader          : categoryLoader,
                    enableDD        : true,
                    containerScroll : true,
                    selModel        : new Ext.tree.CheckNodeMultiSelectionModel(),
                    rootVisible     : "'. $tree->getRoot()->getIsVisible() .'",
                    useAjax         : '. $tree->getUseAjax() .',
                    switchTreeUrl   : "'. $tree->getSwitchTreeUrl() .'",
                    editUrl         : "'. $scriptBaseUrl .'",
                    currentNodeId   : "'. $tree->getCategoryId() .'"
                };

                defaultLoadTreeParams = {
                    parameters : {
                        text        : "'. htmlentities($tree->getRoot()->getName()) .'",
                        draggable   : false,
                        allowDrop   : false,
                        id          : '.  (int) $tree->getRoot()->getId() .',
                        expanded    : '.  (int) $tree->getIsWasExpanded() .',
                        store_id    : '.  (int) $tree->getStore()->getId() .',
                        category_id : '.  (int) $tree->getCategoryId() .'
                    },
                    data : '. $tree->getTreeJson() .'
                };

                reRenderTree();
            });

            /**
             * Update category content area
             */
            function updateContent(url, params, refreshTree) {
                if (!params) {
                    params = {};
                }
                if (!params.form_key) {
                    params.form_key = FORM_KEY;
                }
                
                var categoryContainer = $(\'category-edit-container\');
                var messagesContainer = $(\'messages\');
                var thisObj = this;

                new Ajax.Request(url + (url.match(new RegExp(\'\\\\?\')) ? \'&isAjax=true\' : \'?isAjax=true\' ), {
                    method     : \'post\',
                    parameters:  params,
                    evalScripts: true,
                    onComplete: function () {
                        /**
                         * This func depends on variables, that came in response, and were eval\'ed in onSuccess() callback.
                         * Since prototype\'s Element.update() evals javascripts in 10 msec, we should exec our func after it.
                         */
                        setTimeout(function() {
                            try {
                                if (refreshTree) {
                                    thisObj.refreshTreeArea();
                                }
                                toolbarToggle.start();
                            } catch (e) {
                                alert(e.message);
                            };
                        }, 25);
                    },
                    onSuccess: function(transport) {
                        try {
                            if (transport.responseText.isJSON()) {
                                var response = transport.responseText.evalJSON();
                                var needUpdate = true;
                                if (response.error) {
                                    alert(response.message);
                                    needUpdate = false;
                                }
                                if(response.ajaxExpired && response.ajaxRedirect) {
                                    setLocation(response.ajaxRedirect);
                                    needUpdate = false;
                                }

                                if (needUpdate){

                                    if (response.content){
                                        $(categoryContainer).update(response.content);
                                    }
                                    if (response.messages){
                                        $(messagesContainer).update(response.messages);
                                    }
                                }
                            } else {
                                $(categoryContainer).update(transport.responseText);
                            }
                        }
                        catch (e) {
                            $(categoryContainer).update(transport.responseText);
                        }
                    }
                });
            }

            /**
             * Refresh tree nodes after saving or deleting a category
             */
            function refreshTreeArea(transport)
            {
                if (tree && window.editingCategoryBreadcrumbs) {
                    // category deleted - delete its node
                    if (tree.nodeForDelete) {
                        var node = tree.getNodeById(tree.nodeForDelete);
                        tree.nodeForDelete = false;

                        if (node) { // Check maybe tree became somehow not synced with ajax and we\'re trying to delete unknown node
                            node.parentNode.removeChild(node);
                            tree.currentNodeId = false;
                        }
                    }
                    // category created - add its node
                    else if (tree.addNodeTo) {
                        var parent = tree.getNodeById(tree.addNodeTo);
                        tree.addNodeTo = false;

                        if (parent) { // Check maybe tree became somehow not synced with ajax and we\'re trying to add to unknown node
                            var node = new Ext.tree.AsyncTreeNode(editingCategoryBreadcrumbs[editingCategoryBreadcrumbs.length - 1]);
                            node.loaded = true;
                            tree.currentNodeId = node.id;
                            parent.appendChild(node);

                            if (parent.expanded) {
                                tree.selectCurrentNode();
                            } else {
                                var timer;
                                parent.expand();
                                var f = function(){
                                    if(parent.expanded){ // done expanding
                                        clearInterval(timer);
                                        tree.selectCurrentNode();
                                    }
                                };
                                timer = setInterval(f, 200);
                            }
                        }
                    }

                    // update all affected categories nodes names
                    for (var i = 0; i < editingCategoryBreadcrumbs.length; i++) {
                        var node = tree.getNodeById(editingCategoryBreadcrumbs[i].id);
                        if (node) {
                            node.setText(editingCategoryBreadcrumbs[i].text);
                        }
                    }
                }
            }

            function displayLoadingMask()
            {
               var loaderArea = $$(\'#html-body .wrapper\')[0]; // Blocks all page
                Position.clone($(loaderArea), $(\'loading-mask\'), {offsetLeft:-2});
                toggleSelectsUnderBlock($(\'loading-mask\'), false);
                Element.show(\'loading-mask\');
            }


            function categoryMove(obj)
            {
                var data = {id: obj.dropNode.id, form_key: FORM_KEY, show: \'move-category\'};
                data.point = obj.point;
                switch (obj.point) {
                    case \'above\' :
                        data.pid = obj.target.parentNode.id;
                        data.paid = obj.dropNode.parentNode.id;
                        if (obj.target.previousSibling) {
                            data.aid = obj.target.previousSibling.id;
                        } else {
                            data.aid = 0;
                        }
                        break;
                    case \'below\' :
                        data.pid = obj.target.parentNode.id;
                        data.aid = obj.target.id;
                    break;
                    case \'append\' :
                        data.pid = obj.target.id;
                        data.paid = obj.dropNode.parentNode.id;
                        if (obj.target.lastChild) {
                            data.aid = obj.target.lastChild.id;
                        } else {
                            data.aid = 0;
                        }
                    break;
                    default :
                        obj.cancel = true;
                        return obj;
                }

                var success = function(o) {
                    try {
                        if(o.responseText){
                            if(o.responseText===\'SUCCESS\'){
                            } else {
                                alert(o.responseText);
                                location.reload();
                            }
                        }
                    }
                    catch(e) {
                    }
                };

                var failure = function(o) {
                    try {
                        console.log(o.statusText);
                    } catch (e2) {
                        alert(o.statusText);
                    }
                    location.reload();
                };

                var pd = [];

                for(var key in data) {
                    pd.push(encodeURIComponent(key), "=", encodeURIComponent(data[key]), "&");
                }
                pd.splice(pd.length-1,1);
                new Ajax.Request(
                    "'. $scriptBaseUrl .'",
                    {
                        method:     \'POST\',
                        parameters: pd.join(""),
                        onSuccess : success,
                        onFailure : failure
                    }
                );
            }

            switchStore = function(obj) {
                var storeParam = obj.value;
                if(storeParam !="")
                    setLocation(BASE_URL + "?" + QUERY_PARAMS_WITHOUT_STORE + "&store=" + storeParam );
                else
                    setLocation(BASE_URL + "?" + QUERY_PARAMS_WITHOUT_STORE);
            }
            
            function toggleValueElementsWithCheckbox(checkbox) {
                var td = $(checkbox.parentNode);
                var checkboxes = td.getElementsBySelector(\'input[type="checkbox"]\');
                var inputs = td.getElementsBySelector(\'input[type!="checkbox"]\', \'select\', \'textarea\');
                if (checkboxes.size()>1) {
                    inputs.each(function(input){
                        input.disabled = (!checkbox.checked || checkboxes[0].checked);
                        checkboxes[0].disabled = !checkbox.checked;
                    });
                } else {
                    inputs.each(function(input){
                        input.disabled = !checkbox.checked;
                    });
                }
            }
        //]]>
        </script>';
}


function saveProducts()
{
    global $app;

    /* Collect Data */
    if(!($app->getRequest()->getParam('page', false) || $app->getRequest()->getParam('limit', false))){
        /* Attributes tab form */
        $inventoryData      = $app->getRequest()->getParam('inventory', array());
        $attributesData     = $app->getRequest()->getParam('attributes', array());
        $websiteRemoveData  = $app->getRequest()->getParam('remove_website_ids', array());
        $websiteAddData     = $app->getRequest()->getParam('add_website_ids', array());
        $productIds         = Mage::getSingleton('adminhtml/session')->getProductIds();
    } else {
        /* Products grid in review page */
        $inventoryData      = Mage::getSingleton('adminhtml/session')->getInventoryData();
        $attributesData     = Mage::getSingleton('adminhtml/session')->getAttributesData();
        $websiteRemoveData  = Mage::getSingleton('adminhtml/session')->getWebsiteRemoveData();
        $websiteAddData     = Mage::getSingleton('adminhtml/session')->getWebsiteAddData();
        $productIds         = Mage::getSingleton('adminhtml/session')->getProductIds();
    }
    if(is_string($productIds))
        $productIds = explode(",", $productIds);
    if (!($error = _validateProducts($productIds))) {
        return $error;
    }

    
    /* Prepare inventory data item options (use config settings) */
    foreach (Mage::helper('cataloginventory')->getConfigItemOptions() as $option) {
        if (isset($inventoryData[$option]) && !isset($inventoryData['use_config_' . $option])) {
            $inventoryData['use_config_' . $option] = 0;
        }
    }
    
    try {
        $storeId    = (int) $app->getRequest()->getParam('store', 0);
            
        $collection = Mage::getModel('catalog/product') ->getCollection()
                                                        ->addIdFilter($productIds, false);
        
        /* SETTING THE PROCESSES TO MANUAL UPDATE */
        if(DISABLE_INDEXER){
            $pCollection = Mage::getSingleton('index/indexer')->getProcessesCollection(); 
            foreach ($pCollection as $process) {
                $process->setMode(Mage_Index_Model_Process::MODE_MANUAL)->save();
    //            $process->setMode(Mage_Index_Model_Process::MODE_REAL_TIME)->save();
            }
        }
        
        if ($attributesData) {
            $dateFormat = $app->getLocale()->getDateFormat(Mage_Core_Model_Locale::FORMAT_TYPE_SHORT);
            
            $attributesText = array();

            foreach ($attributesData as $attributeCode => $value) {
                $attribute = Mage::getSingleton('eav/config')
                    ->getAttribute(Mage_Catalog_Model_Product::ENTITY, $attributeCode);
                if (!$attribute->getAttributeId()) {
                    unset($attributesData[$attributeCode]);
                    continue;
                }
                if ($attribute->getBackendType() == 'datetime') {
                    if (!empty($value)) {
                        $filterInput    = new Zend_Filter_LocalizedToNormalized(array(
                            'date_format' => $dateFormat
                        ));
                        $filterInternal = new Zend_Filter_NormalizedToLocalized(array(
                            'date_format' => Varien_Date::DATE_INTERNAL_FORMAT
                        ));
                        $value = $filterInternal->filter($filterInput->filter($value));
                    } else {
                        $value = null;
                    }
                    $attributesData[$attributeCode] = $value;
                } elseif ($attribute->getFrontendInput() == 'multiselect') {
                    // Check if 'Change' checkbox has been checked by admin for this attribute
                    $isChanged = (bool)$app->getRequest()->getPost($attributeCode . '_checkbox');
                    if (!$isChanged) {
                        unset($attributesData[$attributeCode]);
                        continue;
                    }
                    if (is_array($value)) {
                        $value = implode(',', $value);
                    }
                    $attributesData[$attributeCode] = $value;
                }
                
                if($attribute->getFrontendInput() == 'text' || $attribute->getFrontendInput() == 'textarea'){
                    if(strpos($value, "{{current}}") !== false){
                        $attributesText[$attributeCode] = $value;
                        $collection->addAttributeToSelect($attributeCode);
                        unset($attributesData[$attributeCode]);
                    }
                }
            }
            
            if(!empty($attributesText)){
                /**
                 * sort of like Mage_Adminhtml_Block_Catalog_Product_Grid
                 * we replace {{current}}
                 */
//                $collection->load();
                foreach($collection as $item){
                    foreach($attributesText as $attributeCode => $value){
                        $info = $item->getData($attributeCode);
                        $data = str_replace("{{current}}", $info, $value);
                        $item->setData($attributeCode, $data)->getResource()->saveAttribute($item, $attributeCode);
//                        $item->setData($attributeCode, $data);
//                        $item->save();
                    }
                }
            }
            
            Mage::getSingleton('catalog/product_action')
                ->updateAttributes($productIds, $attributesData, $storeId);
        }
        if ($inventoryData) {
            /** @var $stockItem Mage_CatalogInventory_Model_Stock_Item */
            $stockItem = Mage::getModel('cataloginventory/stock_item');
            $stockItem->setProcessIndexEvents(false);
            $stockItemSaved = false;

            foreach ($productIds as $productId) {
                $stockItem->setData(array());
                $stockItem->loadByProduct($productId)
                    ->setProductId($productId);

                $stockDataChanged = false;
                foreach ($inventoryData as $k => $v) {
                    $stockItem->setDataUsingMethod($k, $v);
                    if ($stockItem->dataHasChangedFor($k)) {
                        $stockDataChanged = true;
                    }
                }
                if ($stockDataChanged) {
                    $stockItem->save();
                    $stockItemSaved = true;
                }
            }

            if ($stockItemSaved) {
                Mage::getSingleton('index/indexer')->indexEvents(
                    Mage_CatalogInventory_Model_Stock_Item::ENTITY,
                    Mage_Index_Model_Event::TYPE_SAVE
                );
            }
        }

        if ($websiteAddData || $websiteRemoveData) {
            /* @var $actionModel Mage_Catalog_Model_Product_Action */
            $actionModel = Mage::getSingleton('catalog/product_action');

            if ($websiteRemoveData) {
                $actionModel->updateWebsites($productIds, $websiteRemoveData, 'remove');
            }
            if ($websiteAddData) {
                $actionModel->updateWebsites($productIds, $websiteAddData, 'add');
            }

            /**
             * @deprecated since 1.3.2.2
             */
            Mage::dispatchEvent('catalog_product_to_website_change', array(
                'products' => $productIds
            ));

            $notice = Mage::getConfig()->getNode('adminhtml/messages/website_chnaged_indexers/label');
            if ($notice) {
            }
        }
        
        Mage::getSingleton('adminhtml/session')->unsetData('inventory_data');
        Mage::getSingleton('adminhtml/session')->unsetData('attributes_data');
        Mage::getSingleton('adminhtml/session')->unsetData('website_remove_data');
        Mage::getSingleton('adminhtml/session')->unsetData('website_add_data');
        
        return Mage::helper('adminhtml')->__('Total of %d record(s) were updated', count($productIds));
    } catch(Exception $e){
        
    }
}


function _validateProducts($productIds)
{
    $error = false;
    if (!is_array($productIds)) {
        $error = Mage::helper('adminhtml')->__('Please select products for attributes update');
    } else if (!Mage::getModel('catalog/product')->isProductsHasSku($productIds)) {
        $error = Mage::helper('adminhtml')->__('Some of the processed products have no SKU value defined. Please fill it prior to performing operations on these products.');
    }

    if ($error) {
        return $error;
    }

    return !$error;
}
