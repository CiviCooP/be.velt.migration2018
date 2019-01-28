<?php
use CRM_Migratie2018_ExtensionUtil as E;
function civicrm_api3_huishouden_Relatiefix($params) {
  // eerst alle hoofden van huishouden ophalen
  $hoofdQuery = "SELECT contact_id_a, contact_id_b FROM civicrm_relationship WHERE relationship_type_id = %1";
  $hoofd = CRM_Core_DAO::executeQuery($hoofdQuery, [
    1 => [CRM_Migratie2018_Config::singleton()->getHoofdRelatieTypeId(), 'Integer'],
    ]);
  while ($hoofd->fetch()) {
    // check of er een lid relatie is en zo ja, verwijderen
    $lidQuery = "SELECT id FROM civicrm_relationship WHERE contact_id_a = %1 AND contact_id_b = %2 AND relationship_type_id = %3";
    $lid = CRM_Core_DAO::executeQuery($lidQuery, [
      1 => [$hoofd->contact_id_a, 'Integer'],
      2 => [$hoofd->contact_id_b, 'Integer'],
      3 => [CRM_Migratie2018_Config::singleton()->getLidRelatieTypeId(), 'Integer'],
    ]);
    while ($lid->fetch()) {
      CRM_Core_DAO::executeQuery("DELETE FROM civicrm_relationship WHERE id = %1", [
        1 => [$lid->id, 'Integer'],
      ]);
    }
  }
  return civicrm_api3_create_success([], $params, 'Huishouden', 'relatiefix');
}
