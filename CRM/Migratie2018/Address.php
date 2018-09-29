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
   * Method om data klaar te zetten voor adres
   *
   * @param $sourceData
   * @return bool
   */
  public function prepareFileMakerAdresData($sourceData) {
    Civi::log()->debug(ts('Source data is ' . serialize($sourceData)));

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
    if (isset($sourceData['straat'])) {
      $streetAddress[] = $sourceData['straat'];
    }
    if (isset($sourceData['huisnummer']) && !empty($sourceData['huis'])) {
      $streetAddress[] = (string) $sourceData['huisnummer'];
    }
    if (isset($sourceData['bus']) && !empty($sourceData['bus'])) {
      $streetAddress[] = $sourceData['bus'];
    }
    if (!empty($streetAddress)) {
      $this->_addressData['street_address'] = implode(" ", $streetAddress);
    }
    $this->_addressData['gemeente'] = $this->getCity($sourceData);
    if (isset($sourceData['postcode'])) {
      $this->_addressData['postal_code'] = $sourceData['postcode'];
    }
    if (isset($sourceData['land'])) {
      $countryIso = $this->translateFileMakerCountry($sourceData['land']);
      try {
        $this->_addressData['country_id'] = civicrm_api3('Country', 'getvalue', [
          'return' => 'id',
          'iso_code' => $countryIso,
        ]);
      }
      catch (CiviCRM_API3_Exception $ex) {
      }
    }
    return TRUE;
  }

  /**
   * Method om file maker land te vertalen naar iso code
   *
   * @param $fileMakerLand
   * @return string
   */
  private function translateFileMakerCountry($fileMakerLand) {
    switch ($fileMakerLand) {
      case 1:
        return 'NL';
        break;
      case 2:
        return 'FR';
        break;
      case 3:
        return 'DE';
        break;
      case 4:
        return 'GB';
        break;
      case 5:
        return 'ES';
        break;
      case 6:
        return 'CH';
        break;
      case 7:
        return 'BI';
        break;
        case 8:
        return 'PT';
        break;
      case 9:
        return 'CA';
        break;
      case 10:
        return 'BF';
        break;
      case 11:
        return 'IT';
        break;
      case 12:
        return 'ZA';
        break;
      case 13:
        return 'IE';
        break;
      case 14:
        return 'LU';
        break;
      case 15:
        return 'DK';
        break;
      case 16:
        return 'GR';
        break;
      case 17:
        return 'SE';
        break;
      case 18:
        return 'US';
        break;
      case 19:
        return 'NO';
        break;
      case 20:
        return 'CR';
        break;
      case 21:
        return 'PH';
        break;
      case 22:
        return 'JO';
        break;
      case 23:
        return 'TR';
        break;
      case 24:
        return 'SK';
        break;
      case 25:
        return 'IS';
        break;
      case 26:
        return 'IL';
        break;
      case 27:
        return 'BR';
        break;
      case 28:
        return 'HU';
        break;
      case 29:
        return 'LT';
        break;
      case 30:
        return 'AU';
        break;
      case 31:
        return 'SL';
        break;
      case 32:
        return 'NZ';
        break;
      case 33:
        return 'FI';
        break;
      case 34:
        return 'AT';
        break;
      case 35:
        return 'SG';
        break;
      case 36:
        return 'IE';
        break;
      default:
        return 'BE';
        break;
    }
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
                2 => [$postLetters, 'String'],
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
    Civi::log()->debug(ts('Adres data is ' . serialize($this->_addressData)));
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
    if (!empty($addressId) && !empty($this->_contactId)) {
      try {
        $master = civicrm_api3('Address', 'getsingle', ['id' => $addressId]);
        try {
          $locationType = new CRM_Migratie2018_LocationType();
          $adresData = [
            'contact_id' => $this->_contactId,
            'master_id' => $addressId,
            'location_type_id' => $locationType->determineForContact('address', $this->_contactId),
            'street_address' => $master['street_address'],
            'postal_code' => $master['postal_code'],
            'is_primary' => CRM_Migratie2018_VeltMigratie::setIsPrimary('address', $this->_contactId),
          ];
          if (isset($master['city'])) {
            $adresData['city'] = $master['city'];
          }
          if (isset($master['country_id'])) {
            $adresData['country_id'] = $master['country_id'];
          }
          civicrm_api3('Address', 'Create', $adresData);
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
}