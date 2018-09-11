<?php
/**
 * Class migratie Address
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 14 Mar 2018
 * @license AGPL-3.0
 */

class CRM_Migratie2018_Address {

  private $_addressData = [];
  private $_contactId = NULL;
  private $_logger = NULL;

  /**
   * CRM_Migratie2018_Address constructor.
   *
   * @param int $contactId
   *
   */
  public function __construct($contactId, $logger) {
    $this->_logger = $logger;
    if (empty($contactId)) {
      $this->_logger->logMessage('Fout', 'Geen contact id meegegeven voor adres');
    }
    else {
      $this->_contactId = $contactId;
    }
  }

  /**
   * Method om data klaar te zetten voor adres
   *
   * @param $sourceData
   * @return bool
   */
  public function prepareAdresData($sourceData) {
    if (empty($this->_contactId)) {
      $this->_logger->logMessage('Fout', 'Geen contact id voor adres in ' . __METHOD__);
      return FALSE;
    }
    $locationType = new CRM_Migratie2018_LocationType();
    $this->_addressData = [
      'location_type_id' => $locationType->determineForContact('address', $this->_contactId),
      'is_primary' => CRM_Migratie2018_VeltMigratie::setIsPrimary('address', $this->_contactId),
      'contact_id' => $this->_contactId,
    ];
    $streetAddress = [];
    if (isset($sourceData['street_name'])) {
      $streetAddress[] = $sourceData['street_name'];
    }
    if (isset($sourceData['street_number'])) {
      $streetAddress[] = (string) $sourceData['street_number'];
    }
    if (isset($sourceData['street_number_suffix'])) {
      $streetAddress[] = $sourceData['street_number_suffix'];
    }
    if (!empty($streetAddress)) {
      $this->_addressData['street_address'] = implode(" ", $streetAddress);
    }
    $this->_addressData['city'] = $this->getCity($sourceData);
    if (isset($sourceData['postal_code'])) {
      $this->_addressData['postal_code'] = $sourceData['postal_code'];
    }
    if (isset($sourceData['country_iso']) && !empty($sourceData['country_iso'])) {
      try {
        $this->_addressData['country_id'] = civicrm_api3('Country', 'getvalue', [
          'return' => 'id',
          'iso_code' => $sourceData['country_iso'],
        ]);
      }
      catch (CiviCRM_API3_Exception $ex) {
      }
    }
    return TRUE;
  }

  /**
   * Method om woonplaats te vullen. Als Nederland, haal uit postcode tabel als mogelijk
   *
   * @param $sourceData
   * @return string $result
   */
  private function getCity($sourceData) {
    $result = NULL;
    if (isset($sourceData['city'])) {
      $result = $sourceData['city'];
      // als land Nederland, woonplaats op postcode zoeken
      if ($sourceData['country_iso'] == "NL") {
        if (isset($sourceData['postal_code']) && !empty($sourceData['postal_code'])) {
          if (CRM_Core_DAO::checkTableExists('civicrm_postcodenl')) {
            $postalCode = str_replace(' ', '', $sourceData['postal_code']);
            if (strlen($postalCode) != 6) {
              $this->_logger->logMessage('Waarschuwing', 'NL postcode ' . $postalCode
                . ' onjuist geformatteerd, woonplaats uit bron gebruikt bij contact ID ' . $this->_contactId);
            }
            else {
              $postCijfers = substr($postalCode,0,4);
              $postLetters = substr($postalCode,4,2);
              $query = "SELECT DISTINCT(woonplaats) FROM civicrm_postcodenl 
                WHERE postcode_nr = %1 AND postcode_letter = %2";
              $woonplaats = CRM_Core_DAO::singleValueQuery($query, [
                1 => [$postCijfers, 'Integer'],
                2 => [$postLetters, ' String'],
              ]);
              if (!empty($woonplaats)) {
                $result = $woonplaats;
              }
              else {
                $this->_logger->logMessage('Waarschuwing', 'Geen woonplaats gevonden voor postcode ' . $postalCode . ', woonplaats uit bron gebruikt bij contact ID ' . $this->_contactId);
              }
            }
          }
        } else {
          $this->_logger->logMessage('Waarschuwing', 'Geen postcode voor adres in Nederland, woonplaats uit bron gebruikt bij contact ID ' . $this->_contactId);
        }
      }
    }
    return $result;
  }

  /**
   * Method om adres toe te voegen
   *
   * @return array|bool
   */
  public function create() {
    try {
      $created = civicrm_api3('Address', 'create', $this->_addressData);
      return $created['values'][$created['id']];
    }
    catch (CiviCRM_API3_Exception $ex) {
      $this->_logger->logMessage('Fout', 'Kan geen adres toevoegen voor ' . $this->_addressData['street_address'] . ' met api fout ' .$ex->getMessage());
      return FALSE;
    }
  }

  /**
   * Method om gedeeld adres toe te voegen
   *
   * @param $addressId
   */
  public function createSharedAddress($addressId) {
    try {
      $master = civicrm_api3('Address', 'getsingle', ['id' => $addressId]);
      try {
        $locationType = new CRM_Migratie2018_LocationType();
        civicrm_api3('Address', 'Create', [
          'contact_id' => $this->_contactId,
          'master_id' => $addressId,
          'location_type_id' => $locationType->determineForContact('address', $this->_contactId),
          'street_address' => $master['street_address'],
          'city' => $master['city'],
          'postal_code' => $master['postal_code'],
          'country_id' => $master['country_id'],
          'is_primary' => CRM_Migratie2018_VeltMigratie::setIsPrimary('address', $this->_contactId),
        ]);
      }
      catch (CiviCRM_API3_Exception $ex) {
        $this->_logger->logMessage('Waarschuwing', 'Kan geen gedeeld adres toevoegen voor adres ' .$addressId . ' en contact ' .
          $this->_contactId . ', melding van API Address Create : ' .$ex->getMessage());
      }
    }
    catch (CiviCRM_API3_Exception $ex) {
      $this->_logger->logMessage('Waarschuwing', 'Kon geen adres vinden met id ' . $addressId);
    }
  }
}