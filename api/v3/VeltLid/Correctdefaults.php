<?php
use CRM_Migratie2018_ExtensionUtil as E;

/**
 * VeltLid.Correctdefaults API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_velt_lid_Correctdefaults($params) {
  // tabel leegmaken to be sure
  CRM_Core_DAO::executeQuery('TRUNCATE TABLE civicrm_value_velt_seizoenen_post');
  $lidDataTableName = 'civicrm_value_velt_lid_data';
  $herkomstColumnName = 'vld_herkomst';
  try {
    $herkomst = (int) civicrm_api3('OptionValue', 'getvalue', [
      'option_group_id' => 'velt_herkomst_lidmaatschap',
      'name' => 'Eigen_aanmelding',
      'return' => 'value',
    ]);
    // haal alle lidmaatschappen
    $dao = CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_membership");
    while ($dao->fetch()) {
      // stel seizoenen per post op ja
      $insertSeizoen = 'INSERT INTO civicrm_value_velt_seizoenen_post (entity_id, velt_seizoenen_post) 
        VALUES(%1, %2)';
      CRM_Core_DAO::executeQuery($insertSeizoen, [
        1 => [$dao->id, 'Integer'],
        2 => [1, 'Integer'],
      ]);
      // update herkomst
      $updateHerkomst = 'UPDATE civicrm_value_velt_lid_data SET vld_herkomst = %1 WHERE entity_id = %2';
      CRM_Core_DAO::executeQuery($updateHerkomst, [
        1 => [$herkomst, 'Integer'],
        2 => [$dao->id, 'Integer'],
      ]);
    }
  }
  catch (CiviCRM_API3_Exception $ex) {
  throw new API_Exception(E::ts('Kon geen option value voor herkomst eigen aanmelding vinden!'));
  }
  return civicrm_api3_create_success([], $params, 'VeltLid', 'correctdefaults');
}
