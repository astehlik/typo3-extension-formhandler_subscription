<?php
namespace Tx\FormhandlerSubscription\Updates;

/*                                                                        *
 * This script belongs to the TYPO3 extension "formhandler_subscription". *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\CMS\Install\Updates\AbstractUpdate;

/**
 * Update content elements for formhandler extension. Migrate from plugin to normal content element.
 */
class FormhandlerContentTypeUpdate extends AbstractUpdate
{
    /**
     * @var string
     */
    protected $title = 'Update formhandler content type';

    /**
     * @var string
     */
    protected $oldFormhandlerContentsWhere = "CType='list' AND list_type='formhandler_pi1'";

    /**
     * Checks if an update is needed
     *
     * @param string &$description The description for the update
     * @return bool Whether an update is needed (TRUE) or not (FALSE)
     */
    public function checkForUpdate(&$description)
    {
        if ($this->getDatabaseConnection()->exec_SELECTcountRows('uid', 'tt_content', $this->oldFormhandlerContentsWhere) === 0) {
            return false;
        }

        $description = 'All formhandler content elements that use the old plugin type are updated to use the new content type.';

        return true;
    }

    /**
     * Migrates all formhandler contents.
     *
     * @param array &$databaseQueries Queries done in this update
     * @param mixed &$customMessages Custom messages
     * @return bool
     */
    public function performUpdate(array &$databaseQueries, &$customMessages)
    {
        $db = $this->getDatabaseConnection();
        $db->exec_UPDATEquery('tt_content', $this->oldFormhandlerContentsWhere, ['CType' => 'formhandler_pi1', 'list_type' => '']);
        $databaseQueries[] = $db->debug_lastBuiltQuery;

        return true;
    }
}
