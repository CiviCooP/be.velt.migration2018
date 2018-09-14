<?php
/**
 * Class migratie Contact
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 14 Mar 2018
 * @license AGPL-3.0
 */

class CRM_Migratie2018_Contact {

  private $_contactData = [];
  private $_contactType = NULL;
  private $_logger;
  private $_huishoudenId = NULL;

  /**
   * CRM_Migratie2018_Contact constructor.
   *
   * @param string $contactType
   */
  public function __construct($contactType, $logger, $huishoudenId = NULL) {
    $this->_logger = $logger;
    $validContactTypes = ['Individual', 'Household','Organization'];
    if (!in_array($contactType, $validContactTypes)) {
      CRM_Core_Error::createError('Invalid contact type '. $contactType . ' in ' . __METHOD__);
    }
    if ($huishoudenId) {
      $this->_huishoudenId = $huishoudenId;
    }
    $this->_contactType = $contactType;
  }

  /**
   * Method om data voor het huishouden voor te bereiden
   *
   * @param array $sourceData
   */
  public function prepareHuishoudenData($sourceData) {
    $this->_contactData = ['contact_type' => $this->_contactType];
    if (isset($sourceData['household_name'])) {
      $this->_contactData['household_name'] = $sourceData['household_name'];
    }
    if (isset($sourceData['afdeling_id'])) {
      $this->_contactData['afdeling_id'] = $sourceData['afdeling_id'];
    }
  }

  /**
   * Method om data voor de organisatie voor te bereiden
   *
   * @param array $sourceData
   */
  public function prepareOrganisatieData($sourceData) {
    $this->_contactData = ['contact_type' => $this->_contactType];
    $orgName = [];
    if (isset($sourceData['voornaam']) && !empty($sourceData['voornaam'])) {
      $orgName[] = $sourceData['voornaam'];
    }
    if (isset($sourceData['achternaam']) && !empty($sourceData['achternaam'])) {
      $orgName[] = $sourceData['achternaam'];
    }
    $this->_contactData['organization_name'] = implode(" ", $orgName);
  }

  /**
   * Method om relatie tussen persoon en huishouden toe te voegen
   *
   * @param $individualId
   * @param $huishoudenId
   */
  public function createHuishoudenRelationship($individualId, $huishoudenId) {
    // check eerst of relatie al bestaat (via sql ivm performance)
    $query = "SELECT COUNT(*) FROM civicrm_relationship WHERE relationship_type_id = %1 AND contact_id_a = %2 AND contact_id_b = %3";
    $count = CRM_Core_DAO::singleValueQuery($query, [
      1 => [CRM_Veltbasis_Config::singleton()->getLidHuishoudenRelationshipType('id'), 'Integer'],
      2 => [$individualId, 'Integer'],
      3 => [$huishoudenId, 'Integer'],
    ]);
    switch ($count) {
      case 0:
        try {
          civicrm_api3('Relationship', 'create', [
            'relationship_type_id' => CRM_Veltbasis_Config::singleton()->getLidHuishoudenRelationshipType('id'),
            'is_active' => 1,
            'contact_id_a'=> $individualId,
            'contact_id_b' => $huishoudenId,
          ]);
        }
        catch (CiviCRM_API3_Exception $ex) {
          $this->_logger->logMessage('Waarschuwing', 'Kon geen relatie tussen huishouden ' . $huishoudenId .
            ' en persoon ' . $individualId .' toevoegen, melding van API Relationship Create : ' . $ex->getMessage());
        }
        break;
      case 1:
        $this->_logger->logMessage('Waarschuwing', 'Er is al een lid huishouden relatie tussen persoon ' . $individualId .
          ' en huishouden ' . $huishoudenId . 'geen nieuwe toegevoegd.');
        break;
      default:
        $this->_logger->logMessage('Fout', 'Er zijn al meerdere lid huishouden relaties tussen persoon ' . $individualId .
          ' en huishouden ' . $huishoudenId . ', zoek dit handmatig uit!');
        break;
    }
  }

  /**
   * Method om data voor de persoon voor te bereiden
   * @param $huishoudenId
   * @param $adresId
   * @param $persoonData
   */
  public function preparePersoonData($persoonData) {
    $this->_contactData = [
      'contact_type' => $this->_contactType,
      'first_name' => $persoonData['first_name'],
    ];
    if (isset($persoon['last_name'])) {
      $this->_contactData = $persoonData['last_name'];
    }
    if (isset($persoonData['gender'])) {
      $persoonData['gender'] = strtolower($persoonData['gender']);
    }
    if (isset($persoonData['gender'])) {
      switch ($persoonData['gender']) {
        case 'm':
          $this->_contactData['gender_id'] = 2;
          break;

        case 'v':
          $this->_contactData['gender_id'] = 1;
          break;

        default:
          $this->_contactData['gender_id'] = 3;
          break;
      }
    }
    if (!empty($persoonData['birth_date'])) {
      try {
        $birthDate = new DateTime($persoonData['birth_date']);
        $this->_contactData['birth_date'] = $birthDate->format('Ymd');
      }
      catch (Exception $ex) {
      }
    }
  }


  /**
   * Method om huishouden custom velden toe te voegen
   */
  private function addHuishoudenCustomFields() {
    if (isset($this->_contactData['afdeling_id'])) {
      $customFields = CRM_Veltbasis_Config::singleton()->getHistHuishoudenCustomGroup('custom_fields');
      foreach ($customFields as $customField) {
        if ($customField['name'] == 'velt_oud_hh_afd_id') {
          $customFieldId = 'custom_'.$customField['id'];
          $this->_contactData[$customFieldId] = $this->_contactData['afdeling_id'];
          unset($this->_contactData['afdeling_id']);
        }
      }
    }
  }

  /**
   * Method om contact aan te maken
   *
   * @return array|bool
   */
  public function create() {
    if ($this->_contactType == 'Household') {
      $this->addHuishoudenCustomFields();
    }
    try {
      $created = civicrm_api3('Contact', 'create', $this->_contactData);
      return $created['values'][$created['id']];
    }
    catch (CiviCRM_API3_Exception $ex) {
      switch ($this->_contactType) {
        case 'Household':
          $this->_logger->logMessage('Fout', 'Kan geen huishouden toevoegen met naam ' .
            $this->_contactData['household_name'] . ', api fout ' . $ex->getMessage());
          break;
        case 'Individual':
          $this->_logger->logMessage('Fout', 'Kan geen persoon toevoegen met naam ' .
            $this->_contactData['first_name'] . ' ' . $this->_contactData['last_name'] . ', api fout ' .
            $ex->getMessage());
          break;
        case 'Organization':
          $this->_logger->logMessage('Fout', 'Kan geen organisatie toevoegen met naam ' .
            $this->_contactData['organization_name'] . ', api fout ' .
            $ex->getMessage());
          break;
      }
      return FALSE;
    }
  }

}