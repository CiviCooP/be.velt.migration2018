<?php
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
      $this->_logger->logMessage('Fout', 'Persoon met id ' . $persoon['id'] . '  heeft geen voor- en achternaam, niet gemigreerd!');
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

}