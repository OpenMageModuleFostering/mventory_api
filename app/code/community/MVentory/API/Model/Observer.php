<?php

/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Creative Commons License BY-NC-ND.
 * NonCommercial — You may not use the material for commercial purposes.
 * NoDerivatives — If you remix, transform, or build upon the material,
 * you may not distribute the modified material.
 * See the full license at http://creativecommons.org/licenses/by-nc-nd/4.0/
 *
 * See http://mventory.com/legal/licensing/ for other licensing options.
 *
 * @package MVentory/API
 * @copyright Copyright (c) 2014 mVentory Ltd. (http://mventory.com)
 * @license http://creativecommons.org/licenses/by-nc-nd/4.0/
 */

/**
 * Event handlers
 *
 * @package MVentory/API
 * @author Anatoly A. Kazantsev <anatoly@mventory.com>
 */
class MVentory_API_Model_Observer {
  const __CONFIG_URL = <<<'EOT'
mVentory configuration URL: <a href="%1$s">%1$s</a> (Can only be used once and is valid for %2$d hours)
EOT;

  public function populateAttributes ($observer) {
    if (Mage::helper('mventory/product')->isObserverDisabled($observer))
      return;

    $event = $observer->getEvent();

    //Populate product attributes
    Mage::getSingleton('mventory/product_action')
      ->populateAttributes(array($event->getProduct()), null, false);
  }

  public function saveProductCreateDate ($observer) {
    $product = $observer
                 ->getEvent()
                 ->getProduct();

    if ($product->getId())
      return;

    $product->setData('mv_created_date', time());
  }

  public function productInit ($observer) {
    $product = $observer->getProduct();

    $categories = $product->getCategoryIds();

    if (!count($categories))
      return;

    $categoryId = $categories[0];

    $lastId = Mage::getSingleton('catalog/session')->getLastVisitedCategoryId();

    $category = $product->getCategory();

    //Return if last visited vategory was not used
    if ($category && $category->getId() != $lastId)
      return;

    //Return if categories are same, nothing to change
    if ($lastId == $categoryId)
      return;

    if (!$product->canBeShowInCategory($categoryId))
      return;

    $category = Mage::getModel('catalog/category')->load($categoryId);

    $product->setCategory($category);

    Mage::unregister('current_category');
    Mage::register('current_category', $category);
  }

  public function addProductNameRebuildMassaction ($observer) {
    $block = $observer->getBlock();

    $route = 'mventory/catalog_product/massNameRebuild';

    $label = Mage::helper('mventory')->__('Rebuild product name');
    $url = $block->getUrl($route, array('_current' => true));

    $block
      ->getMassactionBlock()
      ->addItem('namerebuild', compact('label', 'url'));
  }

  /**
   * Add action "Populate product attributes" to admin product manage grid
   */
  public function addProductAttributesPopulateMassaction ($observer) {
    $block = $observer->getBlock();

    $route = 'mventory/catalog_product/massAttributesPopulate';

    $label = Mage::helper('mventory')->__('Populate product attributes');
    $url = $block->getUrl($route, array('_current' => true));

    $block
      ->getMassactionBlock()
      ->addItem('attributespopulate', compact('label', 'url'));
  }

  public function addProductCategoryMatchMassaction ($observer) {
    $block = $observer->getBlock();

    $route = 'mventory/catalog_product/massCategoryMatch';

    $label = Mage::helper('mventory')->__('Match product category');
    $url = $block->getUrl($route, array('_current' => true));

    $block
      ->getMassactionBlock()
      ->addItem('categorymatch', compact('label', 'url'));
  }

  public function syncImages ($observer) {
    $product = $observer->getProduct();

    $attrs = $product->getAttributes();

    if (!isset($attrs['media_gallery']))
      return;

    $galleryAttribute = $attrs['media_gallery'];
    $galleryAttributeId = $galleryAttribute->getAttributeId();

    unset($attrs);

    $helper = Mage::helper('mventory/product_configurable');

    $productId = $product->getId();

    $isConfigurable
      = $product->getTypeId()
          == Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE;

    $configurableId = $isConfigurable
                        ? $productId
                          : $helper->getIdByChild($product);

    if (!$configurableId)
      return;

    $products = $helper->getChildrenIds($configurableId);
    $products[$configurableId] = $configurableId;

    //Make current product first, because we need to collect removed images
    //on first iteration before processing images from other products
    unset($products[$productId]);
    $products = array($productId => $productId) + $products;

    $storeId = $product->getStoreId();

    $mediaAttributes = $product->getMediaAttributes();

    $noMediaValues = true;

    foreach ($mediaAttributes as $code => $attr) {
      if (($data = $product->getData($code)) != 'no_selection')
        $noMediaValues = false;

      $mediaValues[$attr->getAttributeId()] = $data;
    }

    if ($noMediaValues
        && $data = $product
                     ->getData('mventory_assigned_to_configurable_after')) {

      $product = $data['configurable'];

      foreach ($mediaAttributes as $code => $attr)
        $mediaValues[$attr->getAttributeId()] = $product->getData($code);
    }

    unset($product, $mediaAttributes, $data, $noMediaValues);

    $object = new Varien_Object();
    $object->setAttribute($galleryAttribute);

    $product = new Varien_Object();
    $product->setStoreId($storeId);

    $resourse
      = Mage::getResourceSingleton('catalog/product_attribute_backend_media');

    $_images = $observer->getImages();

    foreach ($products as $id => $images) {

      //Don't load gallery for current product
      $gallery = ($id == $productId)
                   ? $_images['images']
                     : $resourse->loadGallery($product->setId($id), $object);

      $products[$id] = array();

      if ($gallery) foreach ($gallery as $image) {
        $file = $image['file'];

        if (isset($image['removed']) && $image['removed']) {
          $imagesToDelete[$file] = true;

          continue;
        }

        if (isset($imagesToDelete[$file])) {
          $idsToDelete[] = $image['value_id'];

          continue;
        }

        $products[$id][$file] = $image;

        if (!isset($allImages[$file]))
          $allImages[$file] = $image;
      }
    }

    unset($imagesToDelete, $_images);

    if (isset($idsToDelete)) {
      foreach ($idsToDelete as $id)
        $resourse->deleteGalleryValueInStore($id, $storeId);

      $resourse->deleteGallery($idsToDelete);
    }

    unset($idsToDelete);

    if (isset($allImages)) foreach ($products as $id => $images) {
      foreach ($allImages as $file => $image) {
        if (!isset($images[$file]))
          $resourse->insertGalleryValueInStore(
            array(
              'value_id' => $resourse->insertGallery(
                array(
                  'entity_id' => $id,
                  'attribute_id' => $galleryAttributeId,
                  'value' => $file
                )
              ),
              'label'  => $image['label'],
              'position' => (int) $image['position'],
              'disabled' => (int) $image['disabled'],
              'store_id' => $storeId
            )
          );
      }
    }

    Mage::getResourceSingleton('catalog/product_action')
      ->updateAttributes(array_keys($products), $mediaValues, $storeId);
  }

  public function resetExcludeFlag ($observer) {
    if (Mage::helper('mventory/product')->isObserverDisabled($observer))
      return;

    $images = $observer->getImages();

    foreach ($images['images'] as &$image)
      $image['disabled'] = 0;
  }

  /**
   * Unset is_duplicate flag to prevent coping image files
   * in Mage_Catalog_Model_Product_Attribute_Backend_Media::beforeSave() method
   *
   * @param Varien_Event_Observer $observer Event observer
   */
  public function unsetDuplicateFlagInProduct ($observer) {
    $observer
      ->getNewProduct()
      ->setIsDuplicate(false)
      ->setOrigIsDuplicate(true);
  }

  /**
   * Restore is_duplicate flag to not affect other code, such as in
   * Mage_Catalog_Model_Product_Attribute_Backend_Media::afterSave() method
   *
   * @param Varien_Event_Observer $observer Event observer
   */
  public function restoreDuplicateFlagInProduct ($observer) {
    $product = $observer->getProduct();

    if ($product->getOrigIsDuplicate())
      $product->setIsDuplicate(true);
  }

  public function addMatchingRulesBlock ($observer) {
    $content = Mage::app()
      ->getFrontController()
      ->getAction()
      ->getLayout()
      ->getBlock('content');

    $matching = $content->getChild('mventory.matching');

    $content
      ->unsetChild('mventory.matching')
      ->append($matching);
  }

  public function matchCategory ($observer) {
    if (Mage::helper('mventory/product')->isObserverDisabled($observer))
      return;

    $product = $observer
                 ->getEvent()
                 ->getProduct();

    if ($product->getIsMventoryCategoryMatched())
      return;

    $result = Mage::getModel('mventory/matching')->matchCategory($product);

    if ($result)
      $product->setCategoryIds((string) $result);
  }

  public function updateDuplicate ($observer) {
    $data = $observer
              ->getCurrentProduct()
              ->getData('mventory_update_duplicate');

    if ($data)
      $observer
        ->getNewProduct()
        ->addData($data);
  }

  public function saveAttributesHash ($observer) {
    $product = $observer->getProduct();

    if ($product->getTypeId()
          == Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE)
      return $this;

    $helper = Mage::helper('mventory');

    $configurable = $helper->getConfigurableAttribute($product->getAttributeSetId());

    if (!($configurable && $configurable->getId()))
      return;

    $storeId = $helper->getCurrentStoreId();

    foreach ($product->getAttributes() as $_attribute) {
      $code = $_attribute->getAttributeCode();

      if (substr($code, -1) == '_'
          && $_attribute
               ->unsetData('store_label')
               ->getStoreLabel($storeId) != '~')

        $data[$code] = is_array($value = $product->getData($code))
                         ? implode(',', $value)
                           : (string) $value;
    }

    if (!isset($data))
      return;

    unset(
      $data[$configurable->getAttributeCode()],
      $data['product_barcode_']
    );

    if ($name = str_replace(' ', '', strtolower($product->getName())))
      $data['name'] = $name;

    if ($data)
      $product->setData(
        'mv_attributes_hash',
        md5(serialize($data))
      );
  }

  public function unassignFromConfigurable ($observer) {
    $product = $observer->getProduct();

    if ($product->getData('mventory_assigned_to_configurable_after') === false
        || !($id = $product->getId())
        || $product->getTypeId()
             == Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE)
      return;

    if (!$oldHash = $product->getOrigData('mv_attributes_hash'))
      return;

    if (($hash = $product->getData('mv_attributes_hash')) == $oldHash)
      return;

    $helper = Mage::helper('mventory/product_configurable');

    if (!$configurableId = $helper->getIdByChild($product))
      return;

    $configurable = Mage::getModel('catalog/product')->load($configurableId);

    if (!$configurable->getId())
      return;

    $childrenIds = $helper->getSiblingsIds($product);

    if ($childrenIds) {
      $attribute = $helper
                   ->getConfigurableAttribute($product->getAttributeSetId());

      $products = Mage::getResourceModel('catalog/product_collection')
                    ->addAttributeToSelect(array(
                      $attribute->getAttributeCode(),
                      'price'
                    ))
                  ->addIdFilter($childrenIds);

      $helper
        ->removeOption($configurable, $attribute, $product)
        ->unassignProduct($configurable, $product)
        ->recalculatePrices($configurable, $attribute, $products);

      $configurable->save();
    } else
      $configurable->delete();

    //Set visibility of the product to 'Catalog, Search' value if it has images
    //otherwise use the value of Product visibility setting
    //from the product's website
    $product->setVisibility(
      $helper->getImages($product, null, false)
        ? Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH
          : (int) $helper->getConfig(
              MVentory_API_Model_Config::_API_VISIBILITY,
              $helper->getWebsite($product)
            )
    );
  }

  public function removeSimilar ($observer) {
    $product = $observer->getProduct();

    if ($product->getData('mventory_assigned_to_configurable_after') === false
        || $product->getTypeId()
             == Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE)
      return $this;

    if (!$hash = $product->getData('mv_attributes_hash'))
      return;

    $helper = Mage::helper('mventory/product_configurable');

    $attribute = $helper
      ->getConfigurableAttribute($product->getAttributeSetId());

    $code = $attribute->getAttributeCode();

    if (($value = $product->getData($code)) === null)
      return;

    $store = $helper
               ->getWebsite($product)
               ->getDefaultStore();

    //Load all similar products (same hash and same value on configurable attr)
    $products = Mage::getResourceModel('catalog/product_collection')
      ->addAttributeToSelect('*')
      ->addAttributeToFilter('type_id', 'simple')
      ->addAttributeToFilter('mv_attributes_hash', $hash)
      ->addAttributeToFilter($code, $value)
      ->addStoreFilter($store);

    //Exclude current product if it exists
    if ($id = $product->getId())
      $products->addIdFilter($id, true);

    if (!count($products))
      return;

    //Update current product with data from similar products, e.g. collect all
    //stock
    $helper->updateFromSimilar($product, $products);

    //Load list of configurable products with same hash and use first
    //product.
    $configurable = Mage::getResourceModel('catalog/product_collection')
      ->addAttributeToSelect('*')
      ->addAttributeToFilter('type_id', 'configurable')
      ->addAttributeToFilter('mv_attributes_hash', $hash)
      ->addStoreFilter($store);

    $configurable = $configurable->count()
                      ? $configurable->getFirstItem()
                        : null;

    if ($configurable) {
      $childrenIds = $helper->getChildrenIds($configurable);
      unset($childrenIds[$id]);
    }

    //Unassigned similar product from the configurable if the product is
    //child of the configurable
    //Collect SKUs of similar products to store them as additional SKUs for
    //the current product
    foreach ($products as $_product) {
      $_id = $_product->getId();

      if ($configurable && in_array($_id, $childrenIds)) {
        $helper
          ->removeOption($configurable, $attribute, $_product)
          ->unassignProduct($configurable, $_product);

        unset($childrenIds[$_id]);
      }

      $skus[] = $_product->getSku();
      $skus[] = $_product->getData('product_barcode_');

      if ($_skus = Mage::getResourceModel('mventory/sku')->get($_id))
        $skus = array_merge($skus, $_skus);
    }

    $product->setData(
      'mventory_additional_skus',
      array_diff(
        array_unique($skus),
        array($product->getSku(), $product->getData('product_barcode_'))
      )
    );

    unset($_product, $_id, $skus, $_skus);

    //Save or remove (if it's has no children) configurable product.
    if ($configurable)
      if ($childrenIds) {
        $configurable->save();
      } else {
        $configurable->delete();

        if ($id)
          $product
            ->setVisibility(4)
            ->setData('mventory_assigned_to_configurable_after', false);
      }

    $products = $products->getItems();

    $_products = $products + array($product);

    //Store distinct images from all similar products and values of media
    //attributes in the product to preserve them.
    //The images is saved in saveImagesAfterMerge() method after
    //the product is saved
    if ($images = Mage::helper('mventory/image')->getUniques($_products)) {
      $data = array(
        'images' => $images,
        'values' => $helper->getMediaAttrs($_products)
      );

      $product->setData('mventory_add_images', $data);
    }

    //On product duplicate Magento copies image DB records from original product
    //to duplicate one (records are shared). If the duplicate is similar
    //to the original product, the original and its image records
    //will be removed, so the duplicate won't have images.
    //So to add images collected on previous step we need to unset value
    //of media_gallery attribute, because on duplicate process images from the
    //media_gallery attribute and collected image are same. It allows for
    //addImages() method of MVentory_API_Helper_Product class to add all images
    //to the duplicate
    if ($product->getOrigIsDuplicate())
      $product->unsMediaGallery();

    //Remove all similar products
    foreach ($products as $_product)
      $_product->delete();
  }

  public function assignToConfigurableBefore ($observer) {
    $product = $observer->getProduct();

    if ($product->getData('mventory_assigned_to_configurable_after') === false
        || $product->getTypeId()
             == Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE)
      return;

    if (!$hash = $product->getData('mv_attributes_hash'))
      return;

    $helper = Mage::helper('mventory/product_configurable');

    if (($id = $product->getId()) && $helper->getIdByChild($product))
      return;

    $attribute = $helper
                   ->getConfigurableAttribute($product->getAttributeSetId());

    $store = $helper
               ->getWebsite($product)
               ->getDefaultStore();

    $products = Mage::getResourceModel('catalog/product_collection')
                  ->addAttributeToSelect('*')
                  ->addFieldToFilter('mv_attributes_hash', $hash)
                  ->addStoreFilter($store);

    if ($id)
      $products->addIdFilter($id, true);

    if (!count($products))
      return;

    foreach ($products as $_product)
      if ($_product->getTypeId()
            == Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE) {

        $configurable = $_product;

        $products->removeItemByKey($configurable->getId());

        break;
      }

    unset($_product);

    //Create configurable by duplicating similar product.
    //We use similar one because we can't duplicate non-existing product.
    if (!isset($configurable))
      $configurable = $helper->create(
                        $products->getFirstItem(),
                        array($attribute->getAttributeCode() => null)
                      );

    if (!$configurable)
      return;

    $product->addData(array(
      'mventory_assigned_to_configurable_after' => array(
        'configurable' => $configurable,
        'attribute' => $attribute,
        'products' => $products
      ),
      'visibility' => 1,
      'description' => $helper->updateDescription($configurable, $product)
    ));
  }

  public function assignToConfigurableAfter ($observer) {
    $product = $observer->getProduct();

    if (!$data = $product->getData('mventory_assigned_to_configurable_after'))
      return $this;

    $configurable = $data['configurable'];
    $attribute = $data['attribute'];
    $products = $data['products']->addItem($product);

    $helper = Mage::helper('mventory/product_configurable');

    $helper
      ->addAttribute($configurable, $attribute, $products)
      ->recalculatePrices($configurable, $attribute, $products)
      ->assignProducts($configurable, $products);

    $updateAll = false;

    if ($configurable->getData('mventory_update_description')) {
      $helper->shareDescription(
        $configurable,
        $products,
        $product->getDescription()
      );

      $updateAll = true;
    }

    $stockItem = Mage::getModel('cataloginventory/stock_item')
                   ->loadByProduct($configurable);

    $configurable
      ->setStockItem($stockItem)
      ->save();

    $products->removeItemByKey($product->getId());

    //Don't sync images when the product was created by duplicating
    //original one. It already has all images.
    if (!$product->getIsDuplicate())
      $this->syncImages($observer);

    foreach ($products as $product) {
      //Set this field to disable updatePricesInConfigurable()
      //and updateDescriptionInConfigurable() methods.
      //Set false value to disable this method.
      $product->setData('mventory_assigned_to_configurable_after', false);

      if ($product->getVisibility() != 1) {
        $product
          ->setVisibility(1)
          ->save();

        continue;
      }

      if ($updateAll)
        $product->save();
    }
  }

  public function updatePricesInConfigurable ($observer) {
    $product = $observer->getProduct();

    //We don't need to update prices because it's already been done in
    //assignToConfigurableAfter() method or product is new
    if ($product->hasData('mventory_assigned_to_configurable_after')
        || $product->getTypeId()
             == Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE)
      return;

    $origPrice = $product->getOrigData('price');

    //Ignore product if it's newly created
    if (($origPrice = $product->getOrigData('price')) === null)
      return;

    $origPrice = (float) $origPrice;
    $price = (float) $product->getData('price');

    if ($price == $origPrice)
      return;

    $helper = Mage::helper('mventory/product_configurable');

    if (!$childrenIds = $helper->getSiblingsIds($product))
      return;

    $configurable = Mage::getModel('catalog/product')
                      ->load($helper->getIdByChild($product));

    if (!$configurable->getId())
      return;

    $attribute = $helper->getConfigurableAttribute(
                   $product->getAttributeSetId()
                 );

    $children = Mage::getResourceModel('catalog/product_collection')
                  ->addAttributeToSelect(array(
                      'price',
                      $attribute->getAttributeCode()
                    ))
                  ->addIdFilter($childrenIds);

    Mage::getResourceModel('cataloginventory/stock')
      ->setInStockFilterToCollection($children);

    $helper->recalculatePrices(
      $configurable,
      $attribute,
      $children->addItem($product)
    );

    $configurable->save();
  }

  public function updatePricesInConfigurableOnStockChange ($observer) {
    $item = $observer->getItem();

    if (!$item->getManageStock())
      return;

    $origStatus = $item->getOrigData('is_in_stock');
    $status = $item->getData('is_in_stock');

    if ($origStatus !== null && $origStatus == $status)
      return;

    $product = $item->getProduct();

    if (!$product)
      $product = Mage::getModel('catalog/product')->load($item->getProductId());

    if (!$product->getId())
      return;

    $helper = Mage::helper('mventory/product_configurable');

    if (!$childrenIds = $helper->getSiblingsIds($product))
      return;

    $storeId = Mage::app()
                 ->getStore(true)
                 ->getId();

    if ($storeId != Mage_Core_Model_App::ADMIN_STORE_ID)
      Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

    $configurable = Mage::getModel('catalog/product')
                      ->load($helper->getIdByChild($product));

    if ($storeId != Mage_Core_Model_App::ADMIN_STORE_ID)
      Mage::app()->setCurrentStore($storeId);

    if (!$configurable->getId())
      return;

    $attribute = $helper->getConfigurableAttribute(
                   $product->getAttributeSetId()
                 );

    $children = Mage::getResourceModel('catalog/product_collection')
                  ->addAttributeToSelect(array(
                      'price',
                      $attribute->getAttributeCode()
                    ))
                  ->addIdFilter($childrenIds);

    Mage::getResourceModel('cataloginventory/stock')
      ->setInStockFilterToCollection($children);

    if ($status)
      $children->addItem($product);

    $helper->recalculatePrices($configurable, $attribute, $children);

    $configurable->save();
  }

  public function updateDescriptionInConfigurable ($observer) {
    $product = $observer->getProduct();

    //We don't need to update prices because it's already been done in
    //assignToConfigurableAfter() method or product is new
    if ($product->hasData('mventory_assigned_to_configurable_after')
        || $product->getTypeId()
             == Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE)
      return;

    $origDescription = $product->getOrigData('description');
    $description = $product->getDescription();

    if ($origDescription == $description)
      return;

    $helper = Mage::helper('mventory/product_configurable');

    if (!$childrenIds = $helper->getSiblingsIds($product))
      return;

    $configurable = Mage::getModel('catalog/product')
                      ->load($helper->getIdByChild($product));

    if (!$configurable->getId())
      return;

    $attribute = $helper
                   ->getConfigurableAttribute($product->getAttributeSetId());

    //Load all product attributes for correct saving
    $children = Mage::getResourceModel('catalog/product_collection')
                  ->addAttributeToSelect('*')
                  ->addIdFilter($childrenIds);

    $helper->shareDescription(
      $configurable,
      $children->addItem($product),
      $description
    );

    $children
      ->removeItemByKey($product->getId())
      ->setDataToAll('mventory_assigned_to_configurable_after', false)
      ->save();

    $configurable->save();
  }

  public function saveAdditionalSkus ($observer) {
    $product = $observer->getProduct();

    if ($skus = $product->getData('mventory_additional_skus'))
      Mage::getResourceModel('mventory/sku')->add(
        $skus,
        $product->getId(),
        Mage::helper('mventory/product')->getWebsite($product)
      );
  }

  public function generateLinkForProfile ($observer) {
    $helper = Mage::helper('mventory');

    if (!$customer = $helper->getCustomerByApiUser($observer->getObject()))
      return;

    if (($websiteId = $customer->getWebsiteId()) === null)
      return;

    $store = Mage::app()
      ->getWebsite($websiteId)
      ->getDefaultStore();

    if ($store->getId() === null)
      return;

    $period = $store->getConfig(MVentory_API_Model_Config::_LINK_LIFETIME) * 60;

    if (!$period)
      return;

    $key = strtr(base64_encode(mcrypt_create_iv(12)), '+/=', '-_,');

    $customer
      ->setData(
          'mventory_app_profile_key',
          $key . '-' . (microtime(true) + $period)
        )
      ->save();

    $msg = $helper->__(
      self::__CONFIG_URL,
      $store->getBaseUrl() . 'mventory-key/' . urlencode($key),
      round($period / 3600)
    );

    Mage::getSingleton('adminhtml/session')->addNotice($msg);
  }

  public function saveImagesAfterMerge ($observer) {
    $product = $observer->getProduct();

    if (!$data = $product->getData('mventory_add_images'))
      return;

    Mage::helper('mventory/product')
      ->addImages($product, $data['images'])
      ->setAttributesValue($product->getId(), $data['values']);
  }

  public function addCreateApiUserButton ($observer) {
    $block = $observer->getData('block');

    if ($block instanceof Mage_Adminhtml_Block_Customer_Edit) {
      $url = $block->getUrl(
        'mventory/customer/createapiuser',
        array(
          '_current' => true,
          'id' => $block->getCustomerId(),
          'tab' => '{{tab_id}}'
        )
      );

      $helper = Mage::helper('mventory');

      $block->addButton(
        'create_api_user',
        array(
          'label' => $helper->__('mVentory Access'),
          'onclick' => sprintf(
            'if (confirm(\'%s\')) setLocation(mv_prepare_url(\'%s\'))',
            $helper->__('Allow database access via mVentory app?'),
            $url
          ),
          'class' => 'add'
        ),
        -1
      );
    }
  }

  public function addAttributeConvertButton ($observer) {
    $block = $observer->getData('block');

    if (!($block instanceof Mage_Adminhtml_Block_Catalog_Product_Attribute_Edit))
      return;

    $attr = Mage::registry('entity_attribute');

    if (!$id = $attr->getId())
      return;

    $url = $block->getUrl(
        'mventory/attribute/convert',
        array(
          '_current' => true,
          //'id' => $id
        )
      );

    $isConverted = substr($attr->getAttributeCode(), -1) === '_';

    $block->addButton(
      'convert_attribute',
      array(
        'label' => $isConverted
                     ? Mage::helper('mventory')->__('Remove from mVentory')
                       : Mage::helper('mventory')->__('Add to mVentory'),
        'onclick' => 'setLocation(\'' . $url . '\')',
        'class' => $isConverted ? 'delete' : 'add'
      ),
      -1
    );
  }
}
