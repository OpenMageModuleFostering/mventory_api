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
 * Backend model for back folder field
 *
 * @package MVentory/API
 * @author Bogdan
 */
class MVentory_API_Model_System_Config_Backend_Imgclip_Backupfolder
  extends Mage_Core_Model_Config_Data
{
  public function _beforeSave () {
    $val = $this->getValue();

    if ($val)
      return $this;

    $path = Mage::getBaseDir('media') . DS . $val;

    if (!is_dir($path))
      mkdir($path);

    if (!(file_exists($path) && is_dir($path) && is_writable($path)))
      Mage::throwException(
        Mage::helper('mventory')->__(
          'Path "%s" for backup folder is invalid!',
          $val
        )
      );

    return $this;
  }
}
