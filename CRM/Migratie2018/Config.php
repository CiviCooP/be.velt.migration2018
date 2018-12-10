<?php
use CRM_Migratie2018_ExtensionUtil as E;

/**
 * Class for Velt Miratie Configuration
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 26 Sept 2018
 * @license AGPL-3.0
 */

class CRM_Migratie2018_Config {

  // property for singleton pattern (caching the config)
  static private $_singleton = NULL;

  // property voor mandaten
  private $_monthlyFrequencyUnit = NULL;
  private $_recurType = NULL;
  private $_recurStatus = NULL;
  private $_newMembershipStatusId = NULL;
  private $_currentMembershipStatusId = NULL;
  private $_expiredMembershipStatusId = NULL;
  private $_cancelledMembershipStatusId = NULL;
  private $_membershipFinancialTypeId = NULL;
  private $_adresMembershipFee = NULL;
  private $_completedContributionStatusId = NULL;
  private $_sepaDDRecurringInstrumentId = NULL;
  private $_hoofdRelatieTypeId = NULL;
  private $_lidRelatieTypeId = NULL;

  /**
   * CRM_Migratie2018_Config constructor.
   *
   * @throws API_Exception
   */
  public function __construct() {
    $this->_recurStatus = "RCUR";
    $this->_recurType = "RCUR";
    try {
      $this->_sepaDDRecurringInstrumentId = (int) civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => 'payment_instrument',
        'name' => 'RCUR',
        'return' => 'value',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      throw new API_Exception(E::ts('Kon geen option value in groep payment_instrument vinden met naam SEPA DD Recurring Transaction in ') . __METHOD__);
    }
    try {
      $this->_completedContributionStatusId = (string) civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => 'contribution_status',
        'name' => 'Completed',
        'return' => 'value',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      throw new API_Exception(E::ts('Kon geen option value in groep contribution_status vinden met name Completed in ') . __METHOD__);
    }
    try {
      $this->_monthlyFrequencyUnit = (string) civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => 'recur_frequency_units',
        'name' => 'month',
        'return' => 'value',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      throw new API_Exception(E::ts('Kon geen option value in groep recur_frequency_units vinden met name year in ') . __METHOD__);
    }
    // Standaard moet financieel type Contributie zijn, als niet gevonden kijken of Member Dues wel bestaat
    $query = "SELECT id FROM civicrm_financial_type WHERE name = %1";
    $contributieType = CRM_Core_DAO::singleValueQuery($query, [1 => ['Contributie', 'String']]);
    if ($contributieType) {
      $this->_membershipFinancialTypeId = (int) $contributieType;
    }
    else {
      $query = "SELECT id FROM civicrm_financial_type WHERE name = %1";
      $contributieType = CRM_Core_DAO::singleValueQuery($query, [1 => ['Member Dues', 'String']]);
      if ($contributieType) {
        $this->_membershipFinancialTypeId = (int) $contributieType;
      }
      else {

        throw new API_Exception(E::ts('Kon geen financieel type Contributie vinden in ') . __METHOD__);
      }
    }
    $query = "SELECT minimum_fee FROM civicrm_membership_type WHERE name = %1 LIMIT 1";
    $fee = CRM_Core_DAO::singleValueQuery($query, [1 => ['Adreslid', 'String']]);
    if ($fee) {
      $this->_adresMembershipFee = number_format($fee, 0);
    }
    else {
      throw new API_Exception(E::ts('Kon geen fee vinden voor lidmaatschapstype met name Adreslid in ') . __METHOD__);
    }
    $this->setMembershipStatus();
    $this->setRelationshipTypes();
  }

  /**
   * Getter voor sepa DD recurring payment instrument
   *
   * @return null|int
   */
  public function getSepaDDRecurringInstrumentId() {
    return $this->_sepaDDRecurringInstrumentId;
  }

  /**
   * Getter voor completed bijdrage status
   *
   * @return null|string
   */
  public function getCompletedContributionStatusId() {
    return $this->_completedContributionStatusId;
  }

  /**
   * Getter voor bedrag adreslid
   *
   * @return null|string
   */
  public function getAdresMembershipFee() {
    return $this->_adresMembershipFee;
  }

  /**
   * Getter voor financial type contributie
   *
   * @return null|string
   */
  public function getMembershipFinancialTypeId() {
    return $this->_membershipFinancialTypeId;
  }
  /**
   * Getter voor nieuw lidmaatschap status
   *
   * @return null
   */
  public function getNewMembershipStatusId() {
    return $this->_newMembershipStatusId;
  }

  /**
   * Getter voor actief lidmaatschap status
   *
   * @return null
   */
  public function getCurrentMembershipStatusId() {
    return $this->_currentMembershipStatusId;
  }

  /**
   * Getter voor verlopen lidmaatschap status
   *
   * @return null
   */
  public function getExpiredMembershipStatusId() {
    return $this->_expiredMembershipStatusId;
  }

  /**
   * Getter voor geannuleerd lidmaatschap status
   *
   * @return null
   */
  public function getCancelledMembershipStatusId() {
    return $this->_cancelledMembershipStatusId;
  }

  /**
   * Getter voor jaarlijkse frequentie
   *
   * @return string
   */
  public function getMonthlyYearlyFrequencyUnit() {
    return $this->_monthlyFrequencyUnit;
  }

  /**
   * Getter voor recur status
   *
   * @return string
   */
  public function getRecurStatus() {
    return $this->_recurStatus;
  }

  /**
   * Getter voor recur type
   *
   * @return string
   */
  public function getRecurType() {
    return $this->_recurType;
  }

  /**
   * Getter voor hoofd van huishouden relatie type id
   *
   * @return null
   */
  public function getHoofdRelatieTypeId() {
    return $this->_hoofdRelatieTypeId;
  }

  /**
   * Getter voor lid van huishouden relatie type id
   *
   * @return null
   */
  public function getLidRelatieTypeId() {
    return $this->_lidRelatieTypeId;
  }

  /**
   * Method om membership status vast te houden
   */
  private function setMembershipStatus() {
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_membership_status");
    while ($dao->fetch()) {
      switch ($dao->name) {
        case 'Cancelled':
          $this->_cancelledMembershipStatusId = $dao->id;
          break;

        case 'Current':
          $this->_currentMembershipStatusId = $dao->id;
          break;

        case 'Expired':
          $this->_expiredMembershipStatusId = $dao->id;
          break;

        case 'New':
          $this->_newMembershipStatusId = $dao->id;
          break;
      }
    }
  }

  /**
   * Method om relatietypes in te stellen
   *
   * @throws API_Exception
   */
  private function setRelationshipTypes() {
    try {
      $result = civicrm_api3('RelationshipType', 'get', [
        'sequential' => 1,
        'name_a_b' => ['IN' => ["Head of Household for", "Household Member of"]],
      ]);
      foreach ($result['values'] as $relationshipType) {
        switch ($relationshipType['name_a_b']) {
          case 'Head of Household for':
            $this->_hoofdRelatieTypeId = $relationshipType['id'];
            break;
          case 'Household Member of':
            $this->_lidRelatieTypeId = $relationshipType['id'];
            break;
        }
      }
    }
    catch (CiviCRM_API3_Exception $ex) {
      throw new API_Exception(E::ts('Could not find a relationship type with name_a_b Head of Househod for and/or Household Member of, error from API RelationshipType get: ') . $ex->getMessage());
    }
  }

  /**
   * Function to return singleton object
   *
   * @return CRM_Migratie2018_Config
   * @access public
   * @static
   */
  public static function &singleton() {
    if (self::$_singleton === NULL) {
      self::$_singleton = new CRM_Migratie2018_Config();
    }
    return self::$_singleton;
  }

}
