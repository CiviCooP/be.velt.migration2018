<?php
use CRM_Migratie2018_ExtensionUtil as E;

/**
 * VeltLid.Migrate API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_velt_lid_Migrate($params) {
  set_time_limit(0);
  $returnValues = [];
  $createCount = 0;
  $logCount = 0;
  $logger = new CRM_Migratie2018_Logger();
  $daoSource = CRM_Core_DAO::executeQuery('SELECT * FROM velt_migratie_2018.migratie_adres WHERE migrated IS NULL OR migrated = 0 LIMIT 150');
  while ($daoSource->fetch()) {
    $veltLid = new CRM_Migratie2018_VeltLid($daoSource, $logger);
    $result = $veltLid->migrate();
    if ($result == FALSE) {
      $logCount++;
    } else {
      $createCount++;
    }
  }
  if (empty($daoSource->N)) {
    $returnValues[] = 'Alle velt leden zijn verwerkt.';
  } else {
    $returnValues[] = $createCount.' velt leden overgezet naar CiviCRM, '.$logCount.' niet overgezet vanwege fouten (check log)';
  }
  return civicrm_api3_create_success($returnValues, $params, 'VeltLid', 'Migrate');
}

