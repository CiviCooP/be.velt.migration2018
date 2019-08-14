<?php
use CRM_Migratie2018_ExtensionUtil as E;

/**
 * FmActief.Migrate API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_fm_actief_Migrate($params) {
  set_time_limit(0);
  $returnValues = [];
  $createCount = 0;
  $logCount = 0;
  $logger = new CRM_Migratie2018_Logger();
  $daoSource = CRM_Core_DAO::executeQuery('SELECT * FROM velt_migratie_2018.actief WHERE 
    processed IS NULL OR processed = 0 LIMIT 500');
  while ($daoSource->fetch()) {
    $update = "UPDATE velt_migratie_2018.actief SET processed = %1 WHERE lidnummer = %2";
    CRM_Core_DAO::executeQuery($update, [
      1 => [1, 'Integer'],
      2 => [$daoSource->lidnummer, 'String'],
    ]);
    $veltLid = new CRM_Migratie2018_VeltLid($daoSource, $logger);
    $result = $veltLid->processActiefLid();
    if ($result == FALSE) {
      $logCount++;
    } else {
      $createCount++;
    }
  }
  if (empty($daoSource->N)) {
    $returnValues[] = 'Alle velt actief leden zijn verwerkt.';
  } else {
    $returnValues[] = $createCount.' velt actief leden overgezet naar CiviCRM, '.$logCount.' niet overgezet vanwege fouten (check log)';
  }
  return civicrm_api3_create_success($returnValues, $params, 'FmActief', 'Migrate');
}
