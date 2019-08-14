<?php
use CRM_Migratie2018_ExtensionUtil as E;

/**
 * VeltLid.Fixhoofd API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_velt_lid_Fixhoofd($params) {
  // 14 aug 2019 haal alle onnodige hoofden van het huishouden op die geen lidmaatschap hebben (die staan er dubbel in)
  $query = "SELECT DISTINCT(a.id) AS contact_id FROM civicrm_contact AS a
JOIN civicrm_relationship AS b ON a.id = b.contact_id_a AND b.relationship_type_id = %1
WHERE a.contact_type = %2 AND is_deleted != 1 AND a.id NOT IN(SELECT contact_id FROM civicrm_membership)";
  $dao = CRM_Core_DAO::executeQuery($query, [
    1 => [7, 'Integer'],
    2 => ['Individual', 'String'],
  ]);
  while ($dao->fetch()) {
    try {
      civicrm_api3('Contact', 'delete', ['id' => $dao->contact_id]);
      Civi::log()->info(E::ts('Contact met ID ') . $dao->contact_id . E::ts(' verwijderd.'));
    }
    catch (CiviCRM_API3_Exception $ex) {
      Civi::log()->warning(E::ts('Kon contact met ID ') . $dao->contact_id . E::ts(' niet verwijderen!'));
    }
  }
  return civicrm_api3_create_success([], $params, 'VeltLid', 'fixhoofd');
}
