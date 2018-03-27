<?php

/**
 * Abstract class for Velt Migration to CiviCRM
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 6 March 2018
 * @license AGPL-3.0
 */
abstract class CRM_Migratie2018_VeltMigratie {

  protected $_logger = NULL;
  protected $_sourceData = array();

  /**
   * CRM_Migration2018_VeltMigratie constructor.
   *
   * @param object $sourceData
   * @param object $logger
   */
  public function __construct($sourceData = NULL, $logger = NULL) {
    $this->_sourceData = CRM_Veltbasis_Utils::moveDaoToArray($sourceData);
    $this->_logger = $logger;
  }

  /**
   * Abstract method to migrate incoming data
   */
  abstract public function migrate();

  /**
   * Abstract Method to validate if source data is good enough
   */
  abstract protected function validSourceData();

  /**
   * Method to set tablename based on entity
   *
   * @param $entity
   * @return bool|string
   */
  public static function setTableForEntity($entity) {
    $entity = strtolower($entity);
    switch ($entity) {
      case 'address':
        return 'civicrm_address';
        break;

      case 'email':
        return 'civicrm_email';
        break;

      case 'phone':
        return 'civicrm_phone';
        break;
      default:
        return FALSE;
    }
  }

  /**
   * Method to determine if this is the primary address, email or phone based on assumption that first one is primary
   *
   * @param string $entity
   * @param int $contactId
   * @return int|bool
   */
  public static function setIsPrimary($entity, $contactId) {
    $tableName = self::setTableForEntity($entity);
    if ($tableName) {
      $countQuery = "SELECT count(*) FROM " . $tableName . " WHERE is_primary = 1 AND contact_id = %2";
      $count = CRM_Core_DAO::singleValueQuery($countQuery, [
        1 => [1, 'Integer'],
        2 => [$contactId, 'Integer'],
      ]);
      if ($count == 0) {
        return 1;
      }
      else {
        return 0;
      }
    } else {
      return 0;
    }
  }

  /**
   * Method to get the fax phone type
   *
   * @return array|bool
   */
  public static function getFaxPhoneTypeId() {
    try {
      return civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => 'phone_type',
        'name' => 'Fax',
        'return' => 'value',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      return FALSE;
    }
  }

  /**
   * Method to get the default phone type
   *
   * @return array|bool
   */
  public static function getDefaultPhoneTypeId() {
    try {
      return civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => 'phone_type',
        'name' => 'Phone',
        'return' => 'value',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      return FALSE;
    }
  }

}
