<?php
/**
 * Class migratie Phone
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 14 Mar 2018
 * @license AGPL-3.0
 */

class CRM_Migratie2018_Phone {

  private $_contactId = NULL;
  private $_phoneTypeId = NULL;
  private $_logger = NULL;

  /**
   * CRM_Migratie2018_Phone constructor.
   *
   * @param int $contactId
   *
   */
  public function __construct($contactId, $phoneType, $logger) {
    $this->_logger = $logger;
    if (empty($contactId)) {
      $this->_logger->logMessage('Fout', 'Geen contact id meegegeven voor telefoon');
    }
    $this->_contactId = $contactId;
    if ($phoneType == 'fax') {
      $this->_phoneTypeId = CRM_Migratie2018_VeltMigratie::getFaxPhoneTypeId();
    }
    else {
      $this->_phoneTypeId = CRM_Migratie2018_VeltMigratie::getDefaultPhoneTypeId();
    }
  }

  /**
   * Methode om telefoon toe te voegen
   *
   * @param $phone
   * @return bool
   */
  public function createIfNotExists($phone) {
    if (!empty($phone)) {
      $query = 'SELECT COUNT(*) FROM civicrm_phone WHERE contact_id = %1 AND phone = %2';
      $count = CRM_Core_DAO::singleValueQuery($query, [
        1 => [$this->_contactId, 'Integer'],
        2 => [$phone, 'String'],
      ]);
      switch ($count) {
        case 0:
          try {
            $locationType = new CRM_Migratie2018_LocationType();
            civicrm_api3('Phone', 'create', [
              'phone' => $phone,
              'contact_id' => $this->_contactId,
              'phone_type_id' => $this->_phoneTypeId,
              'is_primary' => CRM_Migratie2018_VeltMigratie::setIsPrimary('email', $this->_contactId),
              'location_type_id' => $locationType->determineForContact('email', $this->_contactId),
            ]);
            return TRUE;
          } catch (CiviCRM_API3_Exception $ex) {
            $this->_logger->logMessage('Waarschuwing', 'Kon geen telefoon maken met ' . $phone . ' voor contact ' . $this->_contactId);
            return FALSE;
          }
          break;
        case 1:
          $this->_logger->logMessage('Waarschuwing', 'Telefoon ' .$phone . ' bestaat al voor contact ' .$this->_contactId . ', niet toegevoegd');
          return FALSE;
          break;
        default:
          $this->_logger->logMessage('Fout', 'Er bestaan al meerdere telefoons ' .$phone . ' voor contact ' .$this->_contactId . ', los handmatig op!');
          return FALSE;
          break;
      }

    }
    else {
      $this->_logger->logMessage('Waarschuwing', 'Telefoon mag niet leeg zijn voor contact ' . $this->_contactId . ' is geen correct e-mailadres!');
      return FALSE;
    }
  }

}