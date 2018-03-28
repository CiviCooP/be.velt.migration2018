<?php
/**
 * Class migratie Membership
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 14 Mar 2018
 * @license AGPL-3.0
 */

class CRM_Migratie2018_Membership {

  private $_lidData = [];
  private $_contactId = NULL;
  private $_logger = NULL;
  private $_adresLidType = [];
  private $_ownerId = NULL;
  private $_currentStatusId = NULL;
  private $_graceStatusId = NULL;
  private $_expiredStatusId = NULL;
  private $_membershipId = NULL;
  private $_bankTransferTypeId = NULL;
  private $_completedContributionStatusId = NULL;

  /**
   * CRM_Migratie2018_Membership constructor.
   *
   * @param array $contactId
   * @param object $logger
   *
   */
  public function __construct($contactId, $logger) {
    $this->_membershipId = NULL;
    $this->_logger = $logger;
    if (empty($contactId)) {
      $this->_logger->logMessage('Fout', 'Geen contact id meegegeven voor lidmaatschap');
    }
    $this->_contactId = $contactId;
    $this->_adresLidType = CRM_Veltbasis_Config::singleton()->getAdresLidType();
    $this->_ownerId = CRM_Veltbasis_Config::singleton()->getVeltContactId();
    try {
      $membershipStatuses = civicrm_api3('MembershipStatus', 'get', [
        'options' => ['limit' => 0],
        'sequential' => 1,
      ])['values'];
      foreach ($membershipStatuses as $membershipStatus) {
        switch ($membershipStatus['name']) {
          case 'Current':
            $this->_currentStatusId = $membershipStatus['id'];
            break;

          case 'Expired':
            $this->_expiredStatusId = $membershipStatus['id'];
            break;

          case 'Grace':
            $this->_graceStatusId = $membershipStatus['id'];
            break;
        }
      }
    }
    catch (CiviCRM_API3_Exception $ex) {
      $this->_logger->logMessage('Fout', 'Kon geen membership statussen vinden in ' . __METHOD__);
    }
    try {
      $this->_completedContributionStatusId = civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => 'contribution_status',
        'name' => 'Completed',
        'return' => 'value',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      $this->_logger->logMessage('Fout', 'Kon geen contribution status met name Completed vinden in ' . __METHOD__);
    }
    try {
      $this->_bankTransferTypeId = civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => 'payment_instrument',
        'name' => 'Bank Transfer',
        'return' => 'value',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      $this->_logger->logMessage('Fout', 'Kon geen payment method met name Bank Transfer vinden in ' . __METHOD__);
    }
  }

  /**
   * Method om lidmaatschap data voor te bereiden
   *
   * @param $sourceData
   * @return bool|int
   */
  public function prepareData($sourceData) {
    $this->_lidData = [
      'contact_id' => $this->_contactId,
      'membership_type_id' => $this->_adresLidType['id'],
      'is_test' => 0,
      'is_pay_later' => 0,
    ];
    if (isset($sourceData['membership_end_date']) && !empty($sourceData['membership_end_date'])) {
      $endDate = new DateTime($sourceData['membership_end_date']);
      $startDate = new DateTime($sourceData['membership_end_date']);
      $startDate->sub(new DateInterval("P1Y"));
      $this->_lidData['start_date'] = $startDate->format('Y-m-d');
      $this->_lidData['end_date'] = $endDate->format('Y-m-d');
    }
    else {
      $this->_lidData['start_date'] = '2018-01-01';
    }
    $nowDate = new DateTime();
    if ($startDate > $nowDate) {
      $this->_lidData[' join_date'] = $nowDate->format(' Y-m-d');
    }
    else {
      $this->_lidData['join_date'] = $this->_lidData['start_date'];
    }
    // status afhankelijk van einddatum
    $this->_lidData['status_id'] = $this->generateStatusId($endDate);
  }

  /**
   * Method om lidmaatschap status te bepalen aan de hand van de einddatum
   * - als leeg of groter dan vandaag -> current
   * - als ouder dan vandaag maar minder dan 1 maand -> grace
   * - anders -> expired
   *
   * @param $endDate
   * @return int
   */
  private function generateStatusId($endDate) {
    $today = new DateTime();
    $expiredDate = new DateTime();
    $expiredDate->sub(new DateInterval('P1M'));
    if (empty($endDate) || $endDate > $expiredDate) {
      return $this->_currentStatusId;
    }
    if ($endDate < $today && $endDate > $expiredDate) {
      return $this->_graceStatusId;
    }
    return $this->_expiredStatusId;
  }

  /**
   * Method om lidmaatschap toe te voegen als het nog niet bestaat
   *
   * @return mixed
   */
  public function createIfNotExists() {
    // eerst kijken of het al bestaat (sql vanwege performance)
    $query = "SELECT COUNT(*) FROM civicrm_membership WHERE contact_id = %1 AND membership_type_id = %2";
    $count = CRM_Core_DAO::singleValueQuery($query, [
      1 => [$this->_contactId, 'Integer'],
      2 => [$this->_adresLidType['id'], 'Integer'],
    ]);
    switch ($count) {
      case 0:
        try {
          $created = civicrm_api3('Membership', 'Create', $this->_lidData);
          $this->_membershipId = $created['id'];
          // als current ook betaling aanmaken
          if ($this->_lidData['status_id'] == $this->_currentStatusId) {
            $this->createPayment();
          }
          return $created;
        }
        catch (CiviCRM_API3_Exception $ex) {
          $this->_logger->logMessage('Fout', 'Kon geen lidmaatschap Adreslid toevoegen voor ' . $this->_contactId . ', melding van API Membership Create : ' . $ex->getMessage());
          return FALSE;
        }
        break;
      case 1:
        $this->_logger->logMessage('Waarschuwing', 'Er is al een lidmaatschap van het type Adreslid voor ' . $this->_contactId);
        return FALSE;
        break;
      default:
        $this->_logger->logMessage('Fout', 'Er zijn al meerdere lidmaatschappen van het type Adreslid voor ' . $this->_contactId . ', los handmatig op!');
        return FALSE;
        break;
    }
  }

  /**
   * Method om betaling voor het lidmaatschap toe te voegen
   *
   * @return bool
   */
  public function createPayment() {
    $startDate = new DateTime($this->_lidData['start_date']);
    $paymentDate = '2017-' . $startDate->format('m-d');
    // create contribution
    try {
      $contribution = civicrm_api3('Contribution', 'create', [
        'contact_id' => $this->_contactId,
        'financial_type_id' => $this->_adresLidType['financial_type_id'],
        'payment_instrument_id' => $this->_bankTransferTypeId,
        'receive_date' => $paymentDate,
        'total_amount' => $this->_adresLidType['minimum_fee'],
        'contribution_status_id' => $this->_completedContributionStatusId,
        'source' => 'Migratie naar CiviCRM 2018',
      ]);
      // create membership payment
      civicrm_api3('MembershipPayment', 'create', [
        'membership_id' => $this->_membershipId,
        'contribution_id' => $contribution['id'],
      ]);

    }
    catch (CiviCRM_API3_Exception $ex) {
      $this->_logger->logMessage('Fout', 'Kon geen contribution en membership payment toevoegen voor ' . $year . ' en contact ' . $this->_contactId);
      return FALSE;
    }
  }
}