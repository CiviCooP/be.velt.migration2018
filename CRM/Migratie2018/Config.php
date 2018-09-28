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
  private $_yearlyFrequencyUnit = NULL;
  private $_recurType = NULL;
  private $_recurStatus = NULL;
  private $_newMembershipStatusId = NULL;
  private $_currentMembershipStatusId = NULL;
  private $_expiredMembershipStatusId = NULL;
  private $_cancelledMembershipStatusId = NULL;
  private $_membershipFinancialTypeId = NULL;
  private $_adresMembershipFee = NULL;
  private $_completedContributionStatusId = NULL;

  /**
   * CRM_Migratie2018_Config constructor.
   *
   * @throws API_Exception
   */
  public function __construct() {
    $this->_recurStatus = "RCUR";
    $this->_recurType = "RCUR";
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
      $this->_yearlyFrequencyUnit = (string) civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => 'recur_frequency_units',
        'name' => 'year',
        'return' => 'value',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      throw new API_Exception(E::ts('Kon geen option value in groep recur_frequency_units vinden met name year in ') . __METHOD__);
    }
    try {
      $this->_membershipFinancialTypeId = (string) civicrm_api3('FinancialType', 'getvalue', [
        'name' => 'Contributie',
        'return' => 'id',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      throw new API_Exception(E::ts('Kon geen financial type vinden met name Contributie in ') . __METHOD__);
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
  public function getYearlyFreqeuncyUnit() {
    return $this->_yearlyFrequencyUnit;
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
