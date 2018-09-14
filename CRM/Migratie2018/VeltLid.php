<?php
use CRM_Migratie2018_ExtensionUtil as E;

/**
 * Class migratie VeltLid
 * - ontvangt dataset met velt lid gegevens
 * - voegt toe:
 *   - personen, huishoudens en relatie daartussen
 *   - adressen bij huishouden en eventueel gedeeld met personen
 *   - emails en telefoonnummers bij personen
 *   - lidmaatschap voor het huishouden
 *   - eigen velden voor huishouden, afdeling en lidmaatschap
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 6 Mar 2018
 * @license AGPL-3.0
 */

class CRM_Migratie2018_VeltLid extends CRM_Migratie2018_VeltMigratie {

  private $_migrateAdresId = NULL;
  private $_huishoudenId = NULL;
  private $_adresId = NULL;
  private $_persoonsData = [];

  /**
   * Method om een lidmaatschap Velt te migreren (gebaseerd op adres)
   *
   * @return bool
   */
  public function migrate() {
    Civi::log()->debug(E::ts('Data is ' . serialize($this->_sourceData)));
    if ($this->validSourceData() == TRUE) {
      // haal personen op
      $this->getPersonen();
      // maak huishouden aan voor lid als nog niet bestaat
      $this->processHuishouden();
      // maak personen aan voor bij het huishouden
      $this->processPersonen();
      // maak lidmaatschap aan voor huishouden
      $this->processLidmaatschap();
      Civi::log()->debug('Gaat nu proberen het migratie record voor succes bij te werken');
      $this->updateHuishoudenSuccess();
      return TRUE;
    }
    Civi::log()->debug('Gaat nu proberen het migratie record voor fout bij te werken');
    $this->updateHuishoudenError();
    return FALSE;
  }

  /**
   * Method om migratie record bij te werken als succesvol gemigreerd
   */
  private function updateHuishoudenSuccess() {
    $query = "UPDATE velt_migratie_2018.migratie_adres SET migrated = %1, migrate_date = %2, contact_id = %3 WHERE lidmaatschap_id = %4";
    CRM_Core_DAO::executeQuery($query, [
      1 => [1, 'Integer'],
      2 => [date('Y-m-d h:i:s'), 'String'],
      3 => [$this->_huishoudenId, 'Integer'],
      4 => [$this->_sourceData['lidmaatschap_id'], 'Integer'],
    ]);
  }

  /**
   * Method om migratie record bij te werken als fout gemigreerd
   */
  private function updateHuishoudenError() {
    $query = "UPDATE velt_migratie_2018.migratie_adres SET migrated = %1, migrate_date = %2 WHERE lidmaatschap_id = %3";
    CRM_Core_DAO::executeQuery($query, [
      1 => [1, 'Integer'],
      2 => [date('Y-m-d h:i:s'), 'String'],
      3 => [$this->_sourceData['lidmaatschap_id'], 'Integer'],
    ]);
  }

  /**
   * Method om een huishouden aan te maken indien nodig
   */
  private function processHuishouden() {
    $huishouden = new CRM_Migratie2018_Contact('Household', $this->_logger);
    $huishouden->prepareHuishoudenData($this->_sourceData);
    $created = $huishouden->create();
    if (isset($created['id'])) {
      $this->_huishoudenId = $created['id'];
    }
    $adres = new CRM_Migratie2018_Address($this->_huishoudenId, $this->_logger);
    if ($adres->prepareAdresData($this->_sourceData)) {
      $created = $adres->create();
      if (isset($created['id'])) {
        $this->_adresId = $created['id'];
      }
    }
    if (!empty($this->_sourceData['iban'])) {
      $this->processBankAccount();
    }
  }
  /**
   * Method om bankrekening aan te maken
   */
  private function processBankAccount() {
    // eerst leeg bank account aanmaken voor contact
    try {
      $createdBankAccount = civicrm_api3('BankingAccount', 'create', [
        'contact_id' => $this->_huishoudenId,
        'data_parsed' => '{}',
      ]);
      $bankAccountId = $createdBankAccount['id'];
      $bankData = [];
      // indien mogelijk land bijwerken
      $country = substr(trim($this->_sourceData['iban']), 0, 2);
      if ($country == 'BE' || $country = 'NL') {
        $bankData['country'] = $country;
      }
      // indien nodig BIC bijwerken
      if (!empty($this->_sourceData['bic'])) {
        $bankData['BIC'] = trim($this->_sourceData['bic']);
      }
      if (!empty($bankData)) {
        $bankBao = new CRM_Banking_BAO_BankAccount();
        $bankBao->get('id', $bankAccountId);
        $bankBao->setDataParsed($bankData);
        $bankBao->save();
      }
      // update/create bank reference
      $referenceParams = [
        'reference' => trim($this->_sourceData['iban']),
        'reference_type_id' => $this->getBankAccountReferenceType(),
        'ba_id' => $bankAccountId,
      ];
      civicrm_api3('BankingAccountReference', 'create', $referenceParams);
    }
    catch (CiviCRM_API3_Exception $ex) {
      $this->_logger->logMessage('Warning', 'Kon geen bankrekeningnummer ' .$this->_sourceData['iban'] . ' toevoegen bij huishouden ' . $this->_huishoudenId);
    }
  }

  /**
   * Method om lidmaatschappen aan te maken
   */
  private function processLidmaatschap() {
    $lidmaatschap = new CRM_Migratie2018_Membership($this->_huishoudenId, $this->_logger);
    $lidmaatschap->prepareData($this->_sourceData);
    $lidmaatschap->createIfNotExists();
  }

  /**
   * Method om personen op te halen
   */
  private function getPersonen() {
    $query = "SELECT * FROM velt_migratie_2018.migratie_persoon WHERE lidmaatschap_id = %1";
    $dao = CRM_Core_DAO::executeQuery($query, [1 => [$this->_sourceData['lidmaatschap_id'], 'Integer']]);
    $achterNamen = [];
    $count = 0;
    while ($dao->fetch()) {
      $this->_persoonsData[] = CRM_Veltbasis_Utils::moveDaoToArray($dao);
      if ($count < 2) {
        $count++;
        $achterNamen[] = $dao->last_name;
      }
    }
    if (!empty($achterNamen)) {
      $this->_sourceData['household_name'] = implode(" & ", $achterNamen);
    }
    else {
      $this->_logger->logMessage('Error', 'Geen achternamen om een naam huishouden mee samen te stellen voor ID ' .
        $this->_sourceData['lidmaatschap_id'] . ' in ' . __METHOD__);
      return FALSE;
    }
  }

  /**
   * Method om persoon bij te werken
   */
  private function processPersonen() {
    $persoon = new CRM_Migratie2018_Contact('Individual', $this->_logger, $this->_huishoudenId);
    foreach ($this->_persoonsData as $migratiePersoon) {
      if ($this->validPersoon($migratiePersoon)) {
        $persoon->preparePersoonData($migratiePersoon);
        $newPersoon = $persoon->create();
        $persoon->createHuishoudenRelationship($newPersoon['id'], $this->_huishoudenId);
        $adres = new CRM_Migratie2018_Address($newPersoon['id'], $this->_logger);
        $adres->createSharedAddress($this->_adresId);
        if (!empty($migratiePersoon['email'])) {
          $email = new CRM_Migratie2018_Email($newPersoon['id'], $this->_logger);
          $email->createIfNotExists($migratiePersoon['email']);
        }
        if (!empty($migratiePersoon['phone'])) {
          $phone = new CRM_Migratie2018_Phone($newPersoon['id'], 'phone', $this->_logger);
          $phone->createIfNotExists($migratiePersoon['phone']);
        }
        if (!empty($migratiePersoon['fax'])) {
          $fax = new CRM_Migratie2018_Phone($newPersoon['id'], 'fax', $this->_logger);
          $fax->createIfNotExists($migratiePersoon['fax']);
        }
      }
    }
  }

  /**
   * Method om vast te stellen of persoon valide data heeft voor migratie
   *
   * @param $persoon
   * @return bool
   */
  private function validPersoon($persoon) {
    if (!$persoon['first_name'] && !$persoon['last_name']) {
      if (isset($persoon['id'])) {
        $this->_logger->logMessage('Fout', 'Persoon met id ' . $persoon['id'] . '  heeft geen voor- en achternaam, niet gemigreerd!');
      }
      else {
        $this->_logger->logMessage('Fout', ts('Persoon met waarden : ' . serialize($persoon) . '  heeft geen voor- en achternaam, niet gemigreerd!'));

      }
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Method om vast te stellen dat ik valid data uit de bron heb
   * @return bool
   */
  protected function validSourceData() {
    if (empty($this->_sourceData)) {
      $this->_logger->logMessage('Error', 'Geen data in sourceData');
      return FALSE;
    }
    // er moeten personen zijn voor dit huishouden
    $query  = "SELECT COUNT(*) FROM velt_migratie_2018.migratie_persoon WHERE lidmaatschap_id = %1";
    $count = CRM_Core_DAO::singleValueQuery($query, [1 => [$this->_sourceData['lidmaatschap_id'], 'Integer']]);
    if ($count == 0) {
      $this->_logger->logMessage('Fout', 'Geen personen voor lidmaatschap ID ' .$this->_sourceData['lidmaatschap_id']);
      return FALSE;
    }
    $this->_migrateAdresId = $this->_sourceData['lidmaatschap_id'];
    return TRUE;
  }

  /** Method om contact od op te halen met lid id
   *
   * @param $lidId
   * @return int|bool
   */
  private function getContactIdMetLidId($lidId) {
    $lidCustomGroup = CRM_Veltbasis_Config::singleton()->getHistLidCustomGroup();
    foreach ($lidCustomGroup['custom_fields'] as $customField) {
      if ($customField['name'] == 'velt_oud_lid_id') {
        $oudLidColumn = $customField['column_name'];
      }
    }
    // eerst lidmaatschap id van nieuwe lidmaatschap ophalen
    $memberQuery = "SELECT entity_id FROM " . $lidCustomGroup['table_name'] . " WHERE " . $oudLidColumn . " = %1";
    $membershipId = CRM_Core_DAO::singleValueQuery($memberQuery, [1 => [$lidId, 'String']]);
    if ($membershipId) {
      // nu huishouden id ophalen
      $huishoudenQuery = "SELECT contact_id FROM civicrm_membership WHERE id = %1";
      $huishoudenId = CRM_Core_DAO::singleValueQuery($huishoudenQuery, [1 => [$membershipId, 'Integer']]);
      if ($huishoudenId) {
        return $huishoudenId;
      }
    }
    return FALSE;
  }

  /**
   * Method om lid voor het leven te migreren naar of bestaand of nieuw huishouden
   *
   * @return bool
   */
  public function processLevenLid() {
    // check of contact al bestaat met oud lidnummer, zo niet maak huishouden + persoon aan
    $huishoudenId = $this->getContactIdMetLidId($this->_sourceData['lidnummer']);
    if (!$huishoudenId) {
      $huishouden = new CRM_Migratie2018_Contact('Household', $this->_logger);
      $huishoudenData = [
        'afdeling_id' => $this->_sourceData['afdeling'],
        'household_name' => $this->_sourceData['voornaam'] . " " . $this->_sourceData['achternaam'],
      ];
      $huishouden->prepareHuishoudenData($huishoudenData);
      $created = $huishouden->create();
      if (isset($created['id'])) {
        $huishoudenId = $created['id'];
      }
    }
    if ($huishoudenId) {
      $adres = new CRM_Migratie2018_Address($huishoudenId, $this->_logger);
      $adres->prepareFileMakerAdresData($this->_sourceData);
      $newAdresId = $adres->create();
      $persoon = new CRM_Migratie2018_Contact('Individual', $this->_logger);
      $persoonData = [
        'first_name' => $this->_sourceData['voornaam'],
        'achternaam' => $this->_sourceData['achternaam'],
      ];
      $persoon->preparePersoonData($persoonData);
      $newPersoon = $persoon->create();
      $persoon->createHuishoudenRelationship($newPersoon['id'], $huishoudenId);
      $adres = new CRM_Migratie2018_Address($newPersoon['id'], $this->_logger);
      $adres->createSharedAddress($newAdresId);
      if (!empty($this->_sourceData['email'])) {
        $email = new CRM_Migratie2018_Email($newPersoon['id'], $this->_logger);
        $email->createIfNotExists($this->_sourceData['email']);
      }
      if (!empty($this->_sourceData['telefoon'])) {
        $phone = new CRM_Migratie2018_Phone($newPersoon['id'], 'phone', $this->_logger);
        $phone->createIfNotExists($this->_sourceData['telefoon']);
      }
      // voeg lidmaatschap voor het leven toe aan huishouden
      $membership = new CRM_Migratie2018_Membership($huishoudenId, $this->_logger);
      $membership->createLidLeven($this->_sourceData);
    }
  }

  /**
   * Method om gratis en ruil lid te migreren naar of bestaand of nieuw huishouden
   *
   * @return bool
   */
  public function processGratisLid() {
    // maak eventueel huishouden/persoon of organisatie aan
    $lidContactId = $this->createGratisContact($this->_sourceData);
    if ($lidContactId) {
      // voeg gratis en ruil lidmaatschap toe
      $membership = new CRM_Migratie2018_Membership($lidContactId, $this->_logger);
      $membership->createGratisLid($this->_sourceData);
    }
    else {
      $this->_logger->logMessage('Fout', E::ts('Kon geen contact en gratis en ruil lidmaatschap toevoegen voor brondata ' . serialize($this->_sourceData)));
    }
  }

  /**
   * Method om gratis en ruil lidmaatschap toe te voegen
   *
   * @param $sourceData
   * @return bool|int
   */
  private function createGratisContact($sourceData) {
    // als persoon x dan huishouden en persoon anders organisatie
    if ($sourceData['persoon'] == "x") {
      $huishoudenId = $this->createGratisHousehold($sourceData);
      if ($huishoudenId) {
      return $huishoudenId;
      }
    }
    else {
      $organisatieId = $this->createGratisOrganisatie($sourceData);
      if ($organisatieId) {
      return $organisatieId;
      }
    }
    return FALSE;
  }

  /**
   * Method om huishouden en persoon aan te maken voor gratis lid
   *
   * @param $sourceData
   * @return bool|int
   */
  private function createGratisHousehold($sourceData) {
    $huishoudenId = $this->getContactIdMetLidId($sourceData['lidnummer']);
    if (!$huishoudenId) {
      $huishouden = new CRM_Migratie2018_Contact('Household', $this->_logger);
      $huishoudenData = [
        'afdeling_id' => $sourceData['afdeling'],
        'household_name' => $sourceData['voornaam'] . " " . $sourceData['achternaam'],
      ];
      $huishouden->prepareHuishoudenData($huishoudenData);
      $created = $huishouden->create();
      if (isset($created['id'])) {
        $huishoudenId = $created['id'];
      }
    }
    if ($huishoudenId) {
      $adres = new CRM_Migratie2018_Address($huishoudenId, $this->_logger);
      $adres->prepareFileMakerAdresData($sourceData);
      $newAdresId = $adres->create();
      $persoon = new CRM_Migratie2018_Contact('Individual', $this->_logger);
      $persoonData = [
        'first_name' => $this->_sourceData['voornaam'],
        'achternaam' => $this->_sourceData['achternaam'],
      ];
      $persoon->preparePersoonData($persoonData);
      $newPersoon = $persoon->create();
      $persoon->createHuishoudenRelationship($newPersoon['id'], $huishoudenId);
      $adres = new CRM_Migratie2018_Address($newPersoon['id'], $this->_logger);
      $adres->createSharedAddress($newAdresId);
      if (!empty($sourceData['email'])) {
        $email = new CRM_Migratie2018_Email($newPersoon['id'], $this->_logger);
        $email->createIfNotExists($sourceData['email']);
      }
      if (!empty($sourceData['telefoon'])) {
        $phone = new CRM_Migratie2018_Phone($newPersoon['id'], 'phone', $this->_logger);
        $phone->createIfNotExists($sourceData['telefoon']);
      }
      return $huishoudenId;
    }
    return FALSE;
  }
  /**
   * Method om organisatie aan te maken voor gratis lid
   *
   * @param $sourceData
   * @return bool|int
   */
  private function createGratisOrganisatie($sourceData) {
    $organisatieId = $this->getContactIdMetLidId($sourceData['lidnummer']);
    if (!$organisatieId) {
      $organisatie = new CRM_Migratie2018_Contact('Organization', $this->_logger);
      $organisatie->prepareOrganisatieData($sourceData);
      $created = $organisatie->create();
      if (isset($created['id'])) {
        $organisatieId = $created['id'];
      }
    }
    if ($organisatieId) {
      $adres = new CRM_Migratie2018_Address($organisatieId, $this->_logger);
      $adres->prepareFileMakerAdresData($sourceData);
      $adres->create();
      if (!empty($sourceData['email'])) {
        $email = new CRM_Migratie2018_Email($organisatieId, $this->_logger);
        $email->createIfNotExists($sourceData['email']);
      }
      if (!empty($sourceData['telefoon'])) {
        $phone = new CRM_Migratie2018_Phone($organisatieId, 'phone', $this->_logger);
        $phone->createIfNotExists($sourceData['telefoon']);
      }
      return $organisatieId;
    }
    return FALSE;
  }
}