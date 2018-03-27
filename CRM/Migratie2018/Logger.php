<?php

/**
 * Class for basic logging of migration
 *
 * @author Erik Hommel (Ci1 March 2017viCooP) <erik.hommel@civicoop.org>
 * @date 6 Mar 2018
 * @license AGPL-3.0
 */
class CRM_Migratie2018_Logger {

  private $_logFile = null;

  /**
   * CRM_Migratie2018_Logger constructor.
   *
   */
  function __construct() {
    $config = CRM_Core_Config::singleton();
    $runDate = new DateTime('now');
    $fileName = $config->configAndLogDir."velt_migration_".$runDate->format('YmdHis').'.log';
    $this->_logFile = fopen($fileName, 'w');
  }

  /**
   * Method to add message to logger
   *
   * @param $type
   * @param $message
   */
  public function logMessage($type, $message) {
    $this->addMessage($type, $message);
  }

  /**
   * Method to log the message
   *
   * @param $type
   * @param $message
   */
  private function addMessage($type, $message) {
    fputs($this->_logFile, date('Y-m-d h:i:s'));
    fputs($this->_logFile, ' ');
    fputs($this->_logFile, $type);
    fputs($this->_logFile, ' ');
    fputs($this->_logFile, $message);
    fputs($this->_logFile, "\n");
  }
}