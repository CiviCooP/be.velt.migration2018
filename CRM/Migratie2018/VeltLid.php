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
    if ($this->validSourceData() == TRUE) {
      // haal personen op
      $this->getPersonen();
      // maak huishouden aan voor lid als nog niet bestaat
      $this->processHuishouden();
      // maak personen aan voor bij het huishouden
      $this->processPersonen();
      // maak lidmaatschap aan voor huishouden
      $this->processLidmaatschap();
      $this->updateHuishoudenSuccess();
      return TRUE;
    }
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
      if (isset($this->_sourceData['bic'])) {
        $this->processBankAccount($this->_huishoudenId, $this->_sourceData['iban'], $this->_sourceData['bic']);
      }
      else {
        $this->processBankAccount($this->_huishoudenId, $this->_sourceData['iban']);
      }
    }
  }

  /**
   * Method om bankrekening aan te maken
   *
   * @param $contactId
   * @param $iban
   * @param $bic
   */
  private function processBankAccount($contactId, $iban, $bic = "") {
    // eerst leeg bank account aanmaken voor contact
    try {
      $createdBankAccount = civicrm_api3('BankingAccount', 'create', [
        'contact_id' => $contactId,
        'data_parsed' => '{}',
      ]);
      $bankAccountId = $createdBankAccount['id'];
      $bankData = [];
      // indien mogelijk land bijwerken
      $country = substr(trim($iban), 0, 2);
      if ($country == 'BE' || $country = 'NL') {
        $bankData['country'] = $country;
      }
      // indien nodig BIC bijwerken
      if (!empty($bic)) {
        $bankData['BIC'] = trim($bic);
      }
      if (!empty($bankData)) {
        $bankBao = new CRM_Banking_BAO_BankAccount();
        $bankBao->get('id', $bankAccountId);
        $bankBao->setDataParsed($bankData);
        $bankBao->save();
      }
      // update/create bank reference
      $referenceParams = [
        'reference' => trim($iban),
        'reference_type_id' => $this->getBankAccountReferenceType(),
        'ba_id' => $bankAccountId,
      ];
      civicrm_api3('BankingAccountReference', 'create', $referenceParams);
    }
    catch (CiviCRM_API3_Exception $ex) {
      $this->_logger->logMessage('Warning', 'Kon geen bankrekeningnummer ' .$iban . ' toevoegen bij huishouden ' . $this->_huishoudenId);
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
    $query = "SELECT * FROM velt_migratie_2018.migratie_persoon WHERE lidmaatschap_id = %1 ORDER BY id ASC";
    $dao = CRM_Core_DAO::executeQuery($query, [1 => [$this->_sourceData['lidmaatschap_id'], 'Integer']]);
    $achterNamen = [];
    $count = 0;
    $eerstePersoon = TRUE;
    while ($dao->fetch()) {
      if ($eerstePersoon) {
        $dao->relationship_type_id = CRM_Migratie2018_Config::singleton()->getHoofdRelatieTypeId();
        $eerstePersoon = FALSE;
      }
      else {
        $dao->relationship_type_id = CRM_Migratie2018_Config::singleton()->getLidRelatieTypeId();
      }
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
        if (isset($newPersoon['id'])) {
          $persoon->createHuishoudenRelationship($newPersoon['id'], $migratiePersoon['relationship_type_id'], $this->_huishoudenId);
          // issue 3958 - geen gedeeld adres meer nodig
          //$adres = new CRM_Migratie2018_Address($newPersoon['id'], $this->_logger);
          //$adres->createSharedAddress($this->_adresId);
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
      if ($customField['name'] == 'vld_historisch_lid_id') {
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
      $newAdres = $adres->create();
      $persoon = new CRM_Migratie2018_Contact('Individual', $this->_logger);
      $persoonData = [
        'first_name' => $this->_sourceData['voornaam'],
        'achternaam' => $this->_sourceData['achternaam'],
      ];
      $persoon->preparePersoonData($persoonData);
      $newPersoon = $persoon->create();
      if (isset($newPersoon['id']) && !empty($newPersoon['id'])) {
        $relationshipTypeId = CRM_Migratie2018_Config::singleton()->getHoofdRelatieTypeId();
        $persoon->createHuishoudenRelationship($newPersoon['id'],$relationshipTypeId, $huishoudenId);
        $adres = new CRM_Migratie2018_Address($newPersoon['id'], $this->_logger);
        $adres->createSharedAddress($newAdres['id']);
        if (!empty($this->_sourceData['email'])) {
          $email = new CRM_Migratie2018_Email($newPersoon['id'], $this->_logger);
          $email->createIfNotExists($this->_sourceData['email']);
        }
        if (!empty($this->_sourceData['telefoon'])) {
          $phone = new CRM_Migratie2018_Phone($newPersoon['id'], 'phone', $this->_logger);
          $phone->createIfNotExists($this->_sourceData['telefoon']);
        }
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
   * Method om archief lid te migreren naar of bestaand of nieuw huishouden
   *
   * @return bool
   */
  public function processArchief() {
    // maak eventueel huishouden/persoon of organisatie aan
    $huishoudenId = $this->createGratisHousehold($this->_sourceData);
    if ($huishoudenId) {
      // voeg archief lidmaatschap toe
      $membership = new CRM_Migratie2018_Membership($huishoudenId, $this->_logger);
      $membership->createArchiefLidmaatschap($this->_sourceData);
    }
    else {
      $this->_logger->logMessage('Fout', E::ts('Kon geen contact en archief lidmaatschap toevoegen voor brondata ' . serialize($this->_sourceData)));
    }
  }

  /**
   * Method om einddatum voor actief lid te zetten
   *
   * @return bool
   */
  public function processActiefLid() {
    // kijk of lidnummer voorkomt en zo ja, zet einddatum naar vervaldatum uit brondata
    $lidCustomGroup = CRM_Veltbasis_Config::singleton()->getHistLidCustomGroup();
    foreach ($lidCustomGroup['custom_fields'] as $customField) {
      if ($customField['name'] == 'vld_historisch_lid_id') {
        $oudLidColumn = $customField['column_name'];
      }
    }
    $query = "SELECT entity_id FROM " . $lidCustomGroup['table_name'] . " WHERE " . $oudLidColumn . " = %1";
    $membershipId = CRM_Core_DAO::singleValueQuery($query, [1 => [$this->_sourceData['lidnummer'], 'String']]);
    if ($membershipId) {
      if (isset($this->_sourceData['vervaldatum']) && !empty ($this->_sourceData['vervaldatum'])) {
        $vervalDatum = new DateTime($this->_sourceData['vervaldatum']);
        try {
          civicrm_api3('Membership', 'create', [
            'id' => $membershipId,
            'end_date' => $vervalDatum->format('d-m-Y'),
          ]);
          return TRUE;
        }
        catch (CiviCRM_API3_Exception $ex) {
          $this->_logger->logMessage('Fout', E::ts('Fout bij het bijwerken van vervaldatum op basis van actief voor lidnummer ')
            . $this->_sourceData['lidnummer'] . E::ts(', foutmelding van API Membership create: ') . $ex->getMessage());
        }
      }
      else {
        $this->_logger->logMessage('Fout', E::ts('Geen of lege vervaldatum voor actief lid met lidnummer ') . $this->_sourceData['lidnummer']);
      }
    }
    else {
      $this->_logger->logMessage('Fout', E::ts('Kon geen lidmaatschap vinden voor actief lid met lidnummer ') . $this->_sourceData['lidnummer']);
    }
    return FALSE;
  }

  /**
   * Method om giften uit filemaker over te zetten
   *
   * @return bool
   */
  public function processGift() {
    // kijk of persoon voorkomt en zo ja, bijdrage toevoegen bij deze persoon
    $contactId = $this->getGiftPersoon($this->_sourceData);
    if (!$contactId) {
      // als niet gevonden, persoon aanmaken
      $contactId = $this->createGiftPersoon($this->_sourceData);
    }
    if ($contactId) {
      // voeg bijdrage (Gift) toe aan gevonden of aangemaakt contact
      $this->addGift($contactId, $this->_sourceData);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Method om mamndaten uit filemaker over te zetten
   *
   * @return bool
   */
  public function processMandaat() {
    // kijk of persoon voorkomt en indien niet, log fout en negeer
    $contactId = $this->getContactIdMetLidId($this->_sourceData['lidnummer']);
    if ($contactId) {
      // mandaat data voorbereiden
      $mandaatData = $this->prepareMandaatData($contactId);
      if (!empty($mandaatData)) {
        try {
          $nieuwMandaat = civicrm_api3('SepaMandate', 'createfull', $mandaatData);
          // daarna mandaat verbinden met lidmaatschap
          $this->connectMandaat($nieuwMandaat['id']);
          // dan bijdrage voor lidmaatschap toevoegen en bijdrage opnemen in mandaat
          $this->createMandaatPayment($nieuwMandaat['values'][$nieuwMandaat['id']], $contactId);
          return TRUE;
        }
        catch (CiviCRM_API3_Exception $ex) {
          $this->_logger->logMessage(E::ts('Fout'), E::ts('Fout bij het aanmaken van een mandaat voor lid ')
            . $this->_sourceData['lidnummer'] . E::ts(', mandaat niet aangemaakt'));
          return FALSE;
        }
      }
    }
    else {
      $this->_logger->logMessage(E::ts('Fout'), E::ts('Kon geen huishouden vinden met lidnummer ')
        . $this->_sourceData['lidnummer'] . E::ts(', geen mandaat aangemaakt voor domiciliëring'));
      return FALSE;
    }
  }

  /**
   * Method om mandaat te verbinden met lidmaatschap
   *
   * @param $mandaatId
   * @param $contactId
   */
  private function connectMandaat($mandaatId) {
    // checken of en welk mandaat veld gebruikt wordt
    $mandaatField = CRM_Migratie2018_Config::singleton()->getMemberMandateColumnName();
    if ($mandaatField) {
      $query = "UPDATE civicrm_value_velt_lid_data SET " . $mandaatField . " = %1 WHERE velt_historisch_lid_id = %2";
      CRM_Core_DAO::executeQuery($query, [
        1 => [$mandaatId, 'Integer'],
        2 => [$this->_sourceData['lidnummer'], 'String'],
      ]);
    }
  }

  /**
   * Method om bijdrage aan te maken voor mandaat
   *
   * @param $mandaat
   * @param $contactId
   */
  private function createMandaatPayment($mandaat, $contactId) {
    // eerst bestaande membership betalingen verwijderen
    $query = "SELECT entity_id FROM civicrm_value_velt_lid_data WHERE velt_historisch_lid_id = %1 LIMIT 1";
    $membershipId = CRM_Core_DAO::singleValueQuery($query, [1 => [$this->_sourceData['lidnummer'], 'String']]);
    if ($membershipId) {
      $payQuery = "SELECT contribution_id FROM civicrm_membership_payment WHERE membership_id = %1";
      $dao = CRM_Core_DAO::executeQuery($payQuery, [1 => [$membershipId, 'Integer']]);
      while ($dao->fetch()) {
        try {
          civicrm_api3('Contribution', 'delete', ['id' => $dao->contribution_id]);
        }
        catch (CiviCRM_API3_Exception $ex) {
        }
      }
      try {
        $contributionData = [
          'contact_id' => $contactId,
          'contribution_recur_id' => $mandaat['entity_id'],
          'financial_type_id' => CRM_Migratie2018_Config::singleton()->getMembershipFinancialTypeId(),
          'total_amount' => CRM_Migratie2018_Config::singleton()->getAdresMembershipFee(),
          'receive_date' => $mandaat['date'],
          'source' => 'Filemaker Domiciliëring Migratie',
          'contribution_status_id' => CRM_Migratie2018_Config::singleton()->getCompletedContributionStatusId(),
        ];
        // payment met sepa recurring als gevonden
        $paymentInstrumentId = CRM_Migratie2018_Config::singleton()->getSepaDDRecurringInstrumentId();
        if ($paymentInstrumentId) {
          $contributionData['payment_instrument_id'] = $paymentInstrumentId;
        }
        $created = civicrm_api3('Contribution', 'create', $contributionData);
        // membership payment aanmaken
        try {
          civicrm_api3('MembershipPayment', 'create', [
            'membership_id' => $membershipId,
            'contribution_id' => $created['id'],
          ]);
        }
        catch (CiviCRM_API3_Exception $ex) {
        }
        // nu first_contribution_id in mandaat bijwerken
        $query = "UPDATE civicrm_sdd_mandate SET first_contribution_id = %1 WHERE id = %2";
        CRM_Core_DAO::executeQuery($query, [
          1 => [$created['id'], 'Integer'],
          2 => [$mandaat['id'], 'Integer'],
        ]);
      }
      catch (CiviCRM_API3_Exception $ex) {
        $this->_logger->logMessage(E::ts('Kon geen bijdrage aanmaken voor mandaat ID ') . $mandaat['id']
          . E::ts(' voor lidnummer ') . $this->_sourceData['lidnummer'] . E::ts(', corrigeer eventueel handmatig'));
      }
    }
  }

  /**
   * Method om data voor mandaat klaar te zetten
   *
   * @param $contactId
   * @return array
   */
  private function prepareMandaatData($contactId) {
    $mandaatData = [];
    if (!empty($contactId)) {
      $mandaatData['creditor_id'] = Civi::settings()->get('batching_default_creditor');
      $mandaatData['financial_type_id'] = CRM_Migratie2018_Config::singleton()->getMembershipFinancialTypeId();
      $mandaatData['contact_id'] = $contactId;
      $mandaatData['source'] = 'Migratie domiciliëringen uit FileMaker';
      if (!empty($this->_sourceData['iban'])) {
        $mandaatData['iban'] = str_replace(' ', '', $this->_sourceData['iban']);
      }
      else {
        $this->_logger->logMessage(E::ts('Fout'), E::ts('Geen iban gevonden bij domiciliëring voor lid ')
          . $this->_sourceData['lidnummer'] . E::ts(', mandaat niet aangemaakt'));
        return [];
      }
      if (!empty($this->_sourceData['bic'])) {
        $mandaatData['bic'] = str_replace(' ', '', $this->_sourceData['bic']);
      }
      $vervalDatum = $this->berekenMandaatStartDatum($this->_sourceData['vervaldatum']);
      if ($vervalDatum) {
        $mandaatData['date'] = $vervalDatum;
      }
      else {
        $this->_logger->logMessage(E::ts('Waarschuwing'), E::ts('Geen vervaldatum gevonden bij domiciliëring voor lid ')
          . $this->_sourceData['lidnummer'] . E::ts(', mandaat start datum op 1 jan 2018 gezet'));
        $mandaatData['date'] = '20180101';
      }
      if (isset($this->_sourceData['tekendatum']) && !empty($this->_sourceData['tekendatum'])) {
        try {
          $tekenDatum = new DateTime($this->_sourceData['tekendatum']);
          $mandaatData['validation_date'] = $tekenDatum->format('Ymd');
        }
        catch (Exception $ex) {
          $this->_logger->logMessage(E::ts('Kon de tekendatum ') . $this->_sourceData['tekendatum']
            . E::ts(' niet omzetten naar DateTime, niet meegenomen naar validation date van het mandaat'));
        }
      }
      $mandaatData['frequency_unit'] = CRM_Migratie2018_Config::singleton()->getMonthlyYearlyFrequencyUnit();
      $mandaatData['frequency_interval'] = 12;
      $mandaatData['type'] = CRM_Migratie2018_Config::singleton()->getRecurType();
      $mandaatData['status'] = CRM_Migratie2018_Config::singleton()->getRecurStatus();
      $mandaatData['amount'] = CRM_Migratie2018_Config::singleton()->getAdresMembershipFee();
      $mandaatData['cycle_day'] = Civi::settings()->get('cycledays');
      // werk eventueel begindatum lidmaatschap bij met mandaat startdatum
      $this->bijwerkenLidmaatschapStartDatum($contactId, $mandaatData['date']);
    }
    return $mandaatData;
  }

  /**
   * Method om de lidmaatschapsdatum bij te werken als de mandaatdatum eerder is
   *
   * @param $contactId
   * @param $mandaatDatum
   */
  private function bijwerkenLidmaatschapStartDatum($contactId, $mandaatDatum) {
    $query = "SELECT start_date, id FROM civicrm_membership WHERE contact_id = %1 AND membership_type_id = %2 AND is_test = %3 
      AND status_id IN (%4, %5) ORDER BY start_date DESC LIMIT 1";
    $dao = CRM_Core_DAO::executeQuery($query, [
      1 => [$contactId, 'Integer'],
      2 => [CRM_Veltbasis_Config::singleton()->getAdresLidType('id'), 'Integer'],
      3 => [0, 'Integer'],
      4 => [CRM_Migratie2018_Config::singleton()->getNewMembershipStatusId(), 'Integer'],
      5 => [CRM_Migratie2018_Config::singleton()->getCurrentMembershipStatusId(), 'Integer'],
    ]);
    if ($dao->fetch()) {
      $start = new DateTime($dao->start_date);
      $mandaat = new DateTime($mandaatDatum);
      if ($start > $mandaat) {
        $update = "UPDATE civicrm_membership SET start_date = %1 WHERE id = %2";
        CRM_Core_DAO::executeQuery($update, [
          1 => [$mandaat->format('Y-m-d'), 'String'],
          2 => [$dao->id, 'Integer'],
        ]);
      }
    }
  }

  /**
   * Method om de startdatum van het mandaat te berekenen
   *
   * @param $vervalDatum
   * @return null|string
   */
  private function berekenMandaatStartDatum($vervalDatum) {
    $startDatum = NULL;
    if (!empty($vervalDatum)) {
      $datum = new DateTime($vervalDatum);
      $startDatum = $datum->modify('-1 year')->format('Ymd');
    }
    return $startDatum;
  }

  /**
   * Method om persoon te vinden voor gift
   *
   * @param $sourceData
   * @return bool|int
   */
  private function getGiftPersoon($sourceData) {
    $persoonCustomGroup = CRM_Veltbasis_Config::singleton()->getPersoonDataCustomGroup();
    foreach ($persoonCustomGroup['custom_fields'] as $customField) {
      if ($customField['name'] == 'vpd_rrn_bsn') {
        $rijksRegColumn = $customField['column_name'];
      }
    }
    // eerst kijken of ik iemand kan vinden met rijksregisternummer
    if (isset($sourceData['rijksregisternummer']) && !empty($sourceData['rijksregisternummer'])) {
      $query = 'SELECT entity_id FROM ' . $persoonCustomGroup['table_name'] . ' WHERE ' . $rijksRegColumn . ' = %1';
      $contactId = CRM_Core_DAO::singleValueQuery($query, [1 => [$sourceData['rijksregisternummer'], 'String']]);
      if ($contactId) {
        return (int) $contactId;
      }
    }
    // als dat niet lukt, zoeken op lidnummer en 1e persoon huishouden selecteren + in nakijkgroep
    if (isset($sourceData['lidnummer']) && !empty($sourceData['lidnummer'])) {
      $huishoudenId = $this->getContactIdMetLidId($sourceData['lidnummer']);
      if ($huishoudenId) {
        $query = "SELECT contact_id_a FROM civicrm_relationship WHERE relationship_type_id = %1 
          AND contact_id_b = %2 ORDER BY contact_id_a LIMIT 1";
        $contactId = CRM_Core_DAO::singleValueQuery($query, [
          1 => [CRM_Veltbasis_Config::singleton()->getLidHuishoudenRelationshipType('id'), 'Integer'],
          2 => [$huishoudenId, 'Integer'],
        ]);
        if ($contactId) {
          // eventueel bijwerken rijksregisternummer
          if (isset($sourceData['rijksregisternummer']) && !empty($sourceData['rijksregisternummer'])) {
            $this->updateRijksRegisterNummer($contactId, $sourceData['rijksregisternummer']);
          }
          return (int) $contactId;
        }
      }
    }
    return FALSE;
  }

  /**
   * Method om rijksregisternummer bij te werken of toe te voegen
   *
   * @param $contactId
   * @param $rijksRegisterNummer
   */
  private function updateRijksRegisterNummer($contactId, $rijksRegisterNummer) {
    $persoonCustomGroup = CRM_Veltbasis_Config::singleton()->getPersoonDataCustomGroup();
    foreach ($persoonCustomGroup['custom_fields'] as $customField) {
      if ($customField['name'] == 'vpd_rrn_bsn') {
        $rijksRegColumn = $customField['column_name'];
      }
    }
    $query = "SELECT COUNT(*) FROM " . $persoonCustomGroup['table_name'] . " WHERE entity_id = %1";
    $count = CRM_Core_DAO::singleValueQuery($query, [1 => [$contactId, 'Integer']]);
    if ($count == 0) {
      $insert = "INSERT INTO " . $persoonCustomGroup['table_name'] . " (entity_id, " . $rijksRegColumn . ") VALUES (%1, %2)";
      CRM_Core_DAO::executeQuery($insert, [
        1 => [$contactId, 'Integer'],
        2 => [$rijksRegisterNummer, 'String'],
      ]);
    }
    else {
      $update = "UPDATE " . $persoonCustomGroup['table_name'] . " SET " . $rijksRegColumn . " = %1 WHERE entity_id = %2";
      CRM_Core_DAO::executeQuery($update, [
        1 => [$rijksRegisterNummer, 'String'],
        2 => [$contactId, 'Integer'],
      ]);
    }
  }

  /**
   * Method om persoon aan te maken voor gift
   *
   * @param $sourceData
   * @return int|bool
   */
  private function createGiftPersoon($sourceData) {
    $persoonCustomGroup = CRM_Veltbasis_Config::singleton()->getPersoonDataCustomGroup();
    foreach ($persoonCustomGroup['custom_fields'] as $customField) {
      if ($customField['name'] == 'vpd_rrn_bsn') {
        $rijksRegField = 'custom_' . $customField['id'];
      }
    }
    $contactData = ['contact_type' => 'Individual'];
    if (!empty($sourceData['voornaam'])) {
      $contactData['first_name'] = trim($sourceData['voornaam']);
    }
    if (!empty($sourceData['achternaam'])) {
      $contactData['last_name'] = trim($sourceData['achternaam']);
    }
    if (!empty($sourceData['rijksregisternummer'])) {
      $contactData[$rijksRegField] = $sourceData['rijksregisternummer'];
    }
    try {
      $created = civicrm_api3('Contact', 'create', $contactData);
      $contactId = $created['id'];
      // eventueel adres toevoegen
      $adres = new CRM_Migratie2018_Address($contactId, $this->_logger);
      $adres->prepareFileMakerAdresData($sourceData);
      $adres->create();
      return $contactId;
    }
    catch (CiviCRM_API3_Exception $ex) {
      $this->_logger->logMessage('Fout', E::ts('Kon geen persoon voor gift toevoegen, foutmelding van Contact create API: '
        . $ex->getMessage() . ', gift niet gemigreerd. Brondata: ' . serialize($sourceData)));
      return FALSE;
    }
  }

  /**
   * Method om bijdrage voor gift toe te voegen
   *
   * @param $contactId
   * @param $sourceData
   */
  private function addGift($contactId, $sourceData) {
    $bijdrageData = [
      'contact_id' => $contactId,
      'financial_type_id' => 'Donatie',
      ];
    if (isset($sourceData['afkomstig']) && !empty($sourceData['afkomstig'])) {
      $bijdrageData['source'] = $sourceData['afkomstig'];
    }
    if (isset($sourceData['ontvangstdatum']) && !empty($sourceData['ontvangstdatum'])) {
      try {
        $ontvangstDatum = new DateTime($sourceData['ontvangstdatum']);
        $bijdrageData['receive_date'] = $ontvangstDatum->format('d-m-Y') . '00:00:00';
      }
      catch (Exception $ex) {
        $this->_logger->logMessages('Waarschuwing', E::ts('Ontvangstdatum ') . $sourceData['ontvangstdatum']
          . E::ts(' kan niet omgezet worden, datum van vandaag wordt gebruikt voor gift bij lid met contact ID ')
          . $contactId);
      }
    }
    if (isset($sourceData['bedrag']) && !empty($sourceData['bedrag'])) {
      $bijdrageData['total_amount'] = (float) $sourceData['bedrag'];
      try {
        civicrm_api3('Contribution', 'create', $bijdrageData);
      }
      catch (CiviCRM_API3_Exception $ex) {
        $this->_logger->logMessage('Fout', E::ts('Fout bij toevoegen gift, foutmelding van API Contribution create : '
          . $ex->getMessage() . ' met data : ' . serialize($sourceData)));
      }
    }
    else {
      $this->_logger->logMessage('Fout', E::ts('Geen bedrag gevonden bij gift met gegevens ' . serialize($sourceData) . ', gift niet gemigreerd!'));
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
   * Method om huishouden en persoon aan te maken voor gratis en archief lid
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
      $newAdres = $adres->create();
      $persoon = new CRM_Migratie2018_Contact('Individual', $this->_logger);
      $persoonData = [
        'first_name' => $sourceData['voornaam'],
        'last_name' => $sourceData['achternaam'],
      ];
      $persoon->preparePersoonData($persoonData);
      $newPersoon = $persoon->create();
      $relationshipTypeId = CRM_Migratie2018_Config::singleton()->getHoofdRelatieTypeId();
      $persoon->createHuishoudenRelationship($newPersoon['id'], $relationshipTypeId, $huishoudenId);
      //$adres = new CRM_Migratie2018_Address($newPersoon['id'], $this->_logger);
      //$adres->createSharedAddress($newAdres['id']);
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