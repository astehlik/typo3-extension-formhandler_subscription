<?php

/*                                                                        *
 * This script belongs to the TYPO3 extension "formhandler_subscription". *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * This processor validates the submitted auth code that was generated by
 * Tx_FormhandlerSubscription_Finisher_GenerateAuthCodeDB and executes the
 * configured action.
 *
 * There are two actions possible at the moment: enableRecord and accessForm.
 *
 * If the action was set to enableRecord the referenced record will be
 * enabled (hidden will be set to 0).
 *
 * If the action was set to accessForm the submitted auth code will be stored
 * in the session and the auth code data and the data of the referenced record
 * will be made available in the GP array.
 */
class Tx_FormhandlerSubscription_PreProcessor_ValidateAuthCodeDB extends Tx_Formhandler_PreProcessor_ValidateAuthCode {

	/**
	 * Auth code related utility functions
	 *
	 * @var Tx_FormhandlerSubscription_Utils_AuthCode
	 */
	protected $utils;

	/**
	 * TYPO3 database
	 *
	 * @var t3lib_db
	 */
	protected $typo3Db;

	/**
	 * Inits the finisher mapping settings values to internal attributes.
	 *
	 * @param array $gp
	 * @param array $settings
	 * @return void
	 */
	public function init($gp, $settings) {

		parent::init($gp, $settings);

		$this->utils = Tx_FormhandlerSubscription_Utils_AuthCode::getInstance();
		$this->typo3Db = $GLOBALS['TYPO3_DB'];
	}

	/**
	 * Checks the submitted auth code, executes the configured action and optionally
	 * redirects the user to a success page if the auth code is valid.
	 *
	 * If the auth code is invalid an exception will be thrown or the user will be
	 * redirected to a configured error page.
	 *
	 * @throws Exception If the validation of the auth code fails and no error page was configured
	 * @return array
	 */
	public function process() {

		try {

			$authCode = $this->utils->getAuthCode();

			if (empty($authCode)) {
				if (!intval($this->settings['authCodeIsOptional'])) {
					$this->utilityFuncs->throwException('validateauthcode_insufficient_params');
				} else {
					return $this->gp;
				}
			}

			$authCodeData = $this->utils->getAuthCodeDataFromDB($authCode);
			if (!isset($authCodeData)) {
				$this->utilityFuncs->throwException('validateauthcode_no_record_found');
			}

			$this->utils->checkAuthCodeAction($authCodeData['action']);

			switch ($authCodeData['action']) {

				case Tx_FormhandlerSubscription_Utils_AuthCode::ACTION_ENABLE_RECORD:
					$this->updateHiddenField($authCodeData);
					$this->invalidateAuthCode($authCodeData);
					break;

				case Tx_FormhandlerSubscription_Utils_AuthCode::ACTION_ACCESS_FORM:

					// Make the auth code available in the form so that it can be
					// submitted as a hidden field
					$this->gp['authCode'] = $authCode;

					// Make the auth code data available so that it can be displayed to the user
					$this->gp['authCodeData'] = $authCodeData;

					switch ($authCodeData['type']) {

						// for independent records we do not need to load the auth code record data
						case Tx_FormhandlerSubscription_Utils_AuthCode::TYPE_INDEPENDENT:
							break;

						// to be backward compatible auth codes with an invalid type will behave like
						// record auth codes
						case Tx_FormhandlerSubscription_Utils_AuthCode::TYPE_RECORD:
						default:

							// Make the auth code  record data available so that it can be displayed to the user
							$authCodeRecordData = $this->utils->getAuthCodeRecordFromDB($authCodeData);
							$this->gp['authCodeRecord'] = $authCodeRecordData;

							if (intval($this->settings['mergeRecordDataToGP'])) {
								$this->gp = array_merge($this->gp, $authCodeRecordData);
							}

							break;
					}

					// Store the authCode in the session so that the user can use it
					// on different pages without the need to append it as a get
					// parameter everytime
					$this->utils->storeAuthCodeInSession($authCode);
					break;
			}

			$redirectPage = $this->utilityFuncs->getSingle($this->settings, 'redirectPage');
			if ($redirectPage) {
				$this->utilityFuncs->doRedirect($redirectPage, $this->settings['correctRedirectUrl'], $this->settings['additionalParams.']);
			}
		} catch(Exception $e) {

				// make sure, invalid auth codes are deleted
			if (isset($authCodeData)) {
				$this->invalidateAuthCode($authCodeData, TRUE);
			}

			$redirectPage = $this->utilityFuncs->getSingle($this->settings, 'errorRedirectPage');
			if ($redirectPage) {
				$this->utilityFuncs->doRedirect($redirectPage, $this->settings['correctRedirectUrl'], $this->settings['additionalParams.']);
			} else {
				throw new Exception($e->getMessage());
			}
		}

		return $this->gp;
	}

	/**
	 * Invalidates the submitted auth code
	 *
	 * @param array $authCodeData
	 * @param bool $forceClearing
	 */
	protected function invalidateAuthCode($authCodeData, $forceClearing = FALSE) {

		if ((!intval($this->settings['doNotInvalidateAuthCode'])) || $forceClearing) {
			$this->utils->clearAuthCodeFromSession();
			$this->utils->clearAuthCodesByRowData($authCodeData);
			$this->gp = $this->utils->clearAuthCodeFromGP($this->gp);
		}
	}

	/**
	 * Enables the record, that is referenced by the submitted auth code
	 *
	 * @param array $authCodeData
	 */
	protected function updateHiddenField($authCodeData) {

		$updateTable = $authCodeData['reference_table'];
		$uidField = $authCodeData['reference_table_uid_field'];
		$uid = $authCodeData['reference_table_uid'];
		$hiddenField = $authCodeData['reference_table_hidden_field'];
		$updateArray = array($hiddenField => 0);

		if (
			isset($this->settings['updateTimestampOnRecordActivation'])
			&& $this->settings['updateTimestampOnRecordActivation']
			&& isset($GLOBALS['TCA'][$updateTable]['ctrl']['tstamp'])
			&& !empty($GLOBALS['TCA'][$updateTable]['ctrl']['tstamp'])
		) {
			$tstampField = $GLOBALS['TCA'][$updateTable]['ctrl']['tstamp'];
			$updateArray[$tstampField] = $GLOBALS['EXEC_TIME'];
		}

		$enableQuery = $this->typo3Db->UPDATEquery($updateTable, $uidField . '=' . $uid, $updateArray);
		$this->utilityFuncs->debugMessage('sql_request', array($enableQuery));
		$enableResult = $this->typo3Db->sql_query($enableQuery);
		if (!$enableResult) {
			$this->utilityFuncs->throwException('SQL error when enabling the record from the authCode: ' . $this->typo3Db->sql_error());
		}
	}
}