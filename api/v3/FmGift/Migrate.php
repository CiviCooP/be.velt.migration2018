<?php
use CRM_Migratie2018_ExtensionUtil as E;

/**
 * FmGift.Migrate API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_fm_gift_Migrate($params) {
  set_time_limit(0);
  $returnValues = [];
  $createCount = 0;
  $logCount = 0;
  $logger = new CRM_Migratie2018_Logger();
  // maak eventueel tijdelijke groep aan
  try {
    $groupCount = civicrm_api3('Group', 'getcount', ['title' => "Nakijken Giften migratie (tijdelijke groep)"]);
    if ($groupCount == 0) {
      civicrm_api3('Group', 'create', ['title' => "Nakijken Giften migratie (tijdelijke groep)"]);
    }
  }
  catch (CiviCRM_API3_Exception $ex) {
    throw new API_Exception(E::ts('Kon geen groep Nakijken Giften migratie (tijdelijke groep) vinden of toevoegen! Melding van API Group: '
      . $ex->getMessage()));
  }
  $daoSource = CRM_Core_DAO::executeQuery('SELECT * FROM velt_migratie_2018.giften WHERE 
    processed IS NULL OR processed = 0 LIMIT 100');
  while ($daoSource->fetch()) {
    $update = "UPDATE velt_migratie_2018.giften SET processed = %1 WHERE lidnummer = %2";
    CRM_Core_DAO::executeQuery($update, [
      1 => [1, 'Integer'],
      2 => [$daoSource->lidnummer, 'String'],
    ]);
    $veltLid = new CRM_Migratie2018_VeltLid($daoSource, $logger);
    $result = $veltLid->processGift();
    if ($result == FALSE) {
      $logCount++;
    } else {
      $createCount++;
    }
  }
  if (empty($daoSource->N)) {
    $returnValues[] = 'Alle velt giften zijn verwerkt.';
  } else {
    $returnValues[] = $createCount.' velt giften overgezet naar CiviCRM, '.$logCount.' niet overgezet vanwege fouten (check log)';
  }
  return civicrm_api3_create_success($returnValues, $params, 'FmGift', 'Migrate');
}
