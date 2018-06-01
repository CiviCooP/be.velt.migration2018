<?php
/**
 * Class migratie Email
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 14 Mar 2018
 * @license AGPL-3.0
 */

class CRM_Migratie2018_Email {

  private $_contactId = NULL;
  private $_logger = NULL;

  /**
   * CRM_Migratie2018_Email constructor.
   *
   * @param int $contactId
   *
   */
  public function __construct($contactId, $logger) {
    $this->_logger = $logger;
    if (empty($contactId)) {
      $this->_logger->logMessage('Fout', 'Geen contact id meegegeven voor email');
    }
    $this->_contactId = $contactId;
  }

  /**
   * Method om email toe te voegen
   *
   * @param $email
   * @return bool
   */
  public function createIfNotExists($email) {
    $email = trim($email);
    $parts = explode('@', $email);
    if (isset($parts[1]) && $parts[1] == 'velt_migratie_2018.be') {
      $email = trim($parts[0] . '@velt.be');
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $query = 'SELECT COUNT(*) FROM civicrm_email WHERE contact_id = %1 AND email = %2';
      $count = CRM_Core_DAO::singleValueQuery($query, [
        1 => [$this->_contactId, 'Integer'],
        2 => [$email, 'String'],
      ]);
      switch ($count) {
        case 0:
          try {
            $locationType = new CRM_Migratie2018_LocationType();
            civicrm_api3('Email', 'create', [
              'email' => $email,
              'contact_id' => $this->_contactId,
              'is_primary' => CRM_Migratie2018_VeltMigratie::setIsPrimary('email', $this->_contactId),
              'location_type_id' => $locationType->determineForContact('email', $this->_contactId),
            ]);
            return TRUE;
          } catch (CiviCRM_API3_Exception $ex) {
            $this->_logger->logMessage('Waarschuwing', 'Kon geen email maken met ' . $email . ' voor contact ' . $this->_contactId);
            return FALSE;
          }
          break;
        case 1:
          $this->_logger->logMessage('Waarschuwing', 'Email ' .$email . ' bestaat al voor contact ' .$this->_contactId . ', niet toegevoegd');
          return FALSE;
          break;
        default:
          $this->_logger->logMessage('Fout', 'Er bestaan al meerdere emails ' .$email . ' voor contact ' .$this->_contactId . ', los handmatig op!');
          return FALSE;
          break;
      }

    }
    else {
      $this->_logger->logMessage('Waarschuwing', 'Email ' . $email . ' voor contact ' . $this->_contactId . ' is geen correct e-mailadres!');
      return FALSE;
    }
  }
}