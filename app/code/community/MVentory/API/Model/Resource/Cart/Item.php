<?php

/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Creative Commons License BY-NC-ND.
 * By Attribution (BY) - You can share this file unchanged, including
 * this copyright statement.
 * Non-Commercial (NC) - You can use this file for non-commercial activities.
 * A commercial license can be purchased separately from mventory.com.
 * No Derivatives (ND) - You can make changes to this file for your own use,
 * but you cannot share or redistribute the changes.  
 *
 * See the full license at http://creativecommons.org/licenses/by-nc-nd/4.0/
 *
 * @package MVentory/API
 * @copyright Copyright (c) 2014 mVentory Ltd. (http://mventory.com)
 * @license http://creativecommons.org/licenses/by-nc-nd/4.0/
 */

/**
 * Resource model for item of the app shipping cart
 *
 * @package MVentory/API
 */
class MVentory_API_Model_Resource_Cart_Item
  extends Mage_Core_Model_Resource_Db_Abstract {

  protected function _construct() {
    $this->_init('mventory/cart_item', 'transaction_id');
    $this->_isPkAutoIncrement = false;
  }

  public function getCart($deleteBeforeTimestamp, $storeId) {
    $date = date('Y-m-d H:i:s', $deleteBeforeTimestamp);
    $sql = 'call GetCart(\''. $date.'\', '.$storeId.')';

    return $this->getReadConnection()->fetchAll($sql);
  }
}
