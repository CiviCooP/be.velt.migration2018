<?php
use CRM_Migratie2018_ExtensionUtil as E;
/**
 * Persoon.Fixaddress API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_persoon_Fixaddress($params) {
  // haal de ids van het huishouden en hun adres op
  $queryHh = "SELECT hh.id AS huishouden_id, adr.id AS adres_id 
    FROM civicrm_contact AS hh JOIN civicrm_address AS adr ON hh.id = adr.contact_id AND adr.is_primary = %1
    WHERE hh.contact_type = %2";
  $hh = CRM_Core_DAO::executeQuery($queryHh, [
    1 => [1, 'Integer'],
    2 => ['Household', 'String'],
  ]);
  while ($hh->fetch()) {
    // haal personen bij huishouden op, verwijder alle evt. adressen van persoon en geef shared address
    $queryPers = "SELECT contact_id_a AS persoon_id FROM civicrm_relationship
      WHERE relationship_type_id = %1 AND contact_id_b = %2";
    $pers = CRM_Core_DAO::executeQuery($queryPers, [
      1 => [CRM_Veltbasis_Config::singleton()->getLidHuishoudenRelationshipType('id'), 'Integer'],
      2 => [$hh->huishouden_id, 'Integer'],
    ]);
    while ($pers->fetch()) {
      CRM_Core_DAO::executeQuery("DELETE FROM civicrm_address WHERE contact_id = %1", [1 => [$pers->persoon_id, 'Integer']]);
      try {
        $master = civicrm_api3('Address', 'getsingle', ['id' => $hh->adres_id]);
        try {
          $locationType = new CRM_Migratie2018_LocationType();
          $adresData = [
            'contact_id' => $pers->persoon_id,
            'master_id' => $hh->adres_id,
            'location_type_id' => $locationType->determineForContact('address', $pers->persoon_id),
            'street_address' => $master['street_address'],
            'postal_code' => $master['postal_code'],
            'is_primary' => 1,
          ];
          if (isset($master['city'])) {
            $adresData['city'] = $master['city'];
          }
          if (isset($master['country_id'])) {
            $adresData['country_id'] = $master['country_id'];
          }
          civicrm_api3('Address', 'Create', $adresData);
        }
        catch (CiviCRM_API3_Exception $ex) {
          Civi::log()->debug('Kan geen gedeeld adres toevoegen voor adres ' .$hh->adres_id . ' en contact ' .
            $pers->persoon_id . ', melding van API Address Create : ' .$ex->getMessage());
        }
      }
      catch (CiviCRM_API3_Exception $ex) {
        Civi::log()->debug('Kon geen adres vinden met id ' . $hh->adres_id);
      }
    }
  }
  return civicrm_api3_create_success([], $params, 'Persoon', 'fixaddress');
}
