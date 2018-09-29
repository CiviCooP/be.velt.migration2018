<?php
/**
 * Class migratie LocationType
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 20 Mar 2018
 * @license AGPL-3.0
 */

class CRM_Migratie2018_LocationType {

  private $_locationTypes = [];

  /**
   * CRM_Migratie2018_LocationType constructor.
   */
  public function __construct() {
    try {
      $this->_locationTypes = civicrm_api3('LocationType', 'get', ['options' => ['limit' => 0]])['values'];
    }
    catch (CiviCRM_API3_Exception $ex) {
    }
  }

  /**
   * Method to return default location type id
   *
   * @return mixed
   */
  private function getDefaultLocationTypeId() {
    foreach ($this->_locationTypes as $locationType) {
      if (isset($locationType['is_default']) && $locationType['is_default'] == TRUE) {
        return $locationType['id'];
      }
    }
    return FALSE;
  }

  /**
   * Method to return the next location type of a contact that has not been used in entity
   *
   * @param $used
   * @return bool
   */
  private function getNextLocationTypeId($used) {
    foreach ($this->_locationTypes as $locationType) {
      if (!isset($locationType['is_default']) || $locationType['is_default'] == FALSE) {
        if (!in_array($locationType['id'], $used)) {
          return $locationType['id'];
        }
      }
    }
    return FALSE;
  }

  /**
   * Method to get the location type id for an entity and contact
   *
   * @param $entity
   * @param $contactId
   * @return bool
   */
  public function determineForContact($entity, $contactId) {
    $tableName = CRM_Migratie2018_VeltMigratie::setTableForEntity($entity);
    if ($tableName) {
      $query = "SELECT location_type_id FROM " . $tableName . " WHERE contact_id = %1";
      $used = [];
      $dao = CRM_Core_DAO::executeQuery($query, [1 => [$contactId, 'Integer']]);
      // return default if none yet
      if ($dao->N == 0) {
        return $this->getDefaultLocationTypeId();
      }
      while ($dao->fetch()) {
        $used[] = $dao->location_type_id;
      }
      $next = $this->getNextLocationTypeId($used);
      if ($next) {
        return $next;
      } else {
        return $this->getDefaultLocationTypeId();
      }
    }
    else {
      return $this->getDefaultLocationTypeId();
    }
  }

}