<?php
use CRM_Migratie2018_ExtensionUtil as E;

/**
 * VeltLid.Clear API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_velt_lid_Clear($params) {
  set_time_limit(0);
  $dao = CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_membership LIMIT 500");
  while ($dao->fetch()) {
    civicrm_api3('Membership', 'delete', ['id' => $dao->id]);
  }
  return civicrm_api3_create_success([], $params, 'VeltLid', 'clear');
}
