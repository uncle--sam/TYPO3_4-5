<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2010-2011 Workspaces Team (http://forge.typo3.org/projects/show/typo3v4-workspaces)
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Grid data service
 *
 * @author Workspaces Team (http://forge.typo3.org/projects/show/typo3v4-workspaces)
 * @package Workspaces
 * @subpackage Service
 */
class tx_Workspaces_Service_GridData {
	protected $currentWorkspace = NULL;
	protected $dataArray = array();
	protected $sort = '';
	protected $sortDir = '';
	protected $workspacesCache = NULL;

	/**
	 * Generates grid list array from given versions.
	 *
	 * @param array $versions All records uids etc. First key is table name, second key incremental integer. Records are associative arrays with uid, t3ver_oid and t3ver_swapmode fields. The pid of the online record is found as "livepid" the pid of the offline record is found in "wspid"
	 * @param object $parameter
	 * @return array
	 * @throws InvalidArgumentException
	 */
	public function generateGridListFromVersions($versions, $parameter, $currentWorkspace) {
			// Read the given parameters from grid. If the parameter is not set use default values.
		$filterTxt = isset($parameter->filterTxt) ? $parameter->filterTxt : '';
		$start = isset($parameter->start) ? intval($parameter->start) : 0;
		$limit = isset($parameter->limit) ? intval($parameter->limit) : 30;
		$this->sort = isset($parameter->sort) ? $parameter->sort : 't3ver_oid';
		$this->sortDir = isset($parameter->dir) ? $parameter->dir : 'ASC';

		if (is_int($currentWorkspace)) {
			$this->currentWorkspace = $currentWorkspace;
		} else {
			throw new InvalidArgumentException('No such workspace defined');
		}

		$data = array();
		$data['data'] = array();

		$this->generateDataArray($versions, $filterTxt);

		$data['total'] = count($this->dataArray);
		$data['data'] = $this->getDataArray($start, $limit);

		return $data;
	}

	/**
	 * Generates grid list array from given versions.
	 *
	 * @param array $versions
	 * @param string $filterTxt
	 * @return void
	 */
	protected function generateDataArray(array $versions, $filterTxt) {
		/** @var $stagesObj Tx_Workspaces_Service_Stages */
		$stagesObj = t3lib_div::makeInstance('Tx_Workspaces_Service_Stages');

		/** @var $workspacesObj Tx_Workspaces_Service_Workspaces */
		$workspacesObj = t3lib_div::makeInstance('Tx_Workspaces_Service_Workspaces');
		$availableWorkspaces = $workspacesObj->getAvailableWorkspaces();

		$workspaceAccess = $GLOBALS['BE_USER']->checkWorkspace($GLOBALS['BE_USER']->workspace);
		$swapStage = ($workspaceAccess['publish_access'] & 1) ? Tx_Workspaces_Service_Stages::STAGE_PUBLISH_ID : 0;
		$swapAccess =  $GLOBALS['BE_USER']->workspacePublishAccess($GLOBALS['BE_USER']->workspace) &&
					   $GLOBALS['BE_USER']->workspaceSwapAccess();

		$this->initializeWorkspacesCachingFramework();

		// check for dataArray in cache
		if ($this->getDataArrayFromCache($versions, $filterTxt) == FALSE) {
			$stagesObj = t3lib_div::makeInstance('Tx_Workspaces_Service_Stages');

			foreach ($versions as $table => $records) {
				$versionArray = array('table' => $table);
				$hiddenField = $this->getTcaEnableColumnsFieldName($table, 'disabled');
				$isRecordTypeAllowedToModify = $GLOBALS['BE_USER']->check('tables_modify', $table);

				foreach ($records as $record) {

					$origRecord = t3lib_BEFunc::getRecord($table, $record['t3ver_oid']);
					$versionRecord = t3lib_BEFunc::getRecord($table, $record['uid']);

					if ($hiddenField !== NULL) {
						$recordState = $this->workspaceState($versionRecord['t3ver_state'], $origRecord[$hiddenField], $versionRecord[$hiddenField]);
					} else {
						$recordState = $this->workspaceState($versionRecord['t3ver_state']);
					}
					$isDeletedPage = ($table == 'pages' && $recordState == 'deleted');
					$viewUrl =  tx_Workspaces_Service_Workspaces::viewSingleRecord($table, $record['uid'], $origRecord, $versionRecord);

					$versionArray['id'] = $table . ':' . $record['uid'];
					$versionArray['uid'] = $record['uid'];
					$versionArray['workspace'] = $versionRecord['t3ver_id'];
					$versionArray['label_Workspace'] = htmlspecialchars(t3lib_befunc::getRecordTitle($table, $versionRecord));
					$versionArray['label_Live'] = htmlspecialchars(t3lib_befunc::getRecordTitle($table, $origRecord));
					$versionArray['label_Stage'] = htmlspecialchars($stagesObj->getStageTitle($versionRecord['t3ver_stage']));
					$versionArray['path_Live'] = htmlspecialchars(t3lib_BEfunc::getRecordPath($record['livepid'], '', 999));
					$versionArray['path_Workspace'] = htmlspecialchars(t3lib_BEfunc::getRecordPath($record['wspid'], '', 999));
					$versionArray['workspace_Title'] = htmlspecialchars(tx_Workspaces_Service_Workspaces::getWorkspaceTitle($versionRecord['t3ver_wsid']));

					$versionArray['workspace_Tstamp'] = $versionRecord['tstamp'];
					$versionArray['workspace_Formated_Tstamp'] = t3lib_BEfunc::datetime($versionRecord['tstamp']);
					$versionArray['t3ver_oid'] = $record['t3ver_oid'];
					$versionArray['livepid'] = $record['livepid'];
					$versionArray['stage'] = $versionRecord['t3ver_stage'];
					$versionArray['icon_Live'] = t3lib_iconWorks::mapRecordTypeToSpriteIconClass($table, $origRecord);
					$versionArray['icon_Workspace'] = t3lib_iconWorks::mapRecordTypeToSpriteIconClass($table, $versionRecord);

					$versionArray['allowedAction_nextStage'] = $isRecordTypeAllowedToModify && $stagesObj->isNextStageAllowedForUser($versionRecord['t3ver_stage']);
					$versionArray['allowedAction_prevStage'] = $isRecordTypeAllowedToModify && $stagesObj->isPrevStageAllowedForUser($versionRecord['t3ver_stage']);

					if ($swapAccess && $swapStage != 0 && $versionRecord['t3ver_stage'] == $swapStage) {
						$versionArray['allowedAction_swap'] = $isRecordTypeAllowedToModify && $stagesObj->isNextStageAllowedForUser($swapStage);
					} elseif ($swapAccess && $swapStage == 0) {
						$versionArray['allowedAction_swap'] = $isRecordTypeAllowedToModify;
					} else {
						$versionArray['allowedAction_swap'] = FALSE;
					}

					$versionArray['allowedAction_delete'] = $isRecordTypeAllowedToModify;
						// preview and editing of a deleted page won't work ;)
					$versionArray['allowedAction_view'] = !$isDeletedPage && $viewUrl;
					$versionArray['allowedAction_edit'] = $isRecordTypeAllowedToModify && !$isDeletedPage;
					$versionArray['allowedAction_editVersionedPage'] = $isRecordTypeAllowedToModify && !$isDeletedPage;

					$versionArray['state_Workspace'] = $recordState;

					if ($filterTxt == '' || $this->isFilterTextInVisibleColumns($filterTxt, $versionArray)) {
						$this->dataArray[] = $versionArray;
					}
				}
			}

			$this->setDataArrayIntoCache($versions, $filterTxt);
		}

		$this->sortDataArray();
	}

	/**
	 * Gets the data array by considering the page to be shown in the grid view.
	 *
	 * @param integer $start
	 * @param integer $limit
	 * @return array
	 */
	protected function getDataArray($start, $limit) {
		$dataArrayPart = array();
		$end = $start + $limit < count($this->dataArray) ? $start + $limit : count($this->dataArray);

		for ($i = $start; $i < $end; $i++) {
			$dataArrayPart[] = $this->dataArray[$i];
		}

		return $dataArrayPart;
	}


	/**
	 * Initialize the workspace cache
	 *
	 * @return void
	 */
	protected function initializeWorkspacesCachingFramework() {
		if (TYPO3_UseCachingFramework === TRUE) {
			try {
				$GLOBALS['typo3CacheFactory']->create(
					'workspaces_cache',
					$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['sys_workspace_cache']['frontend'],
					$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['sys_workspace_cache']['backend'],
					$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['sys_workspace_cache']['options']);
			} catch (t3lib_cache_exception_DuplicateIdentifier $e) {
				// do nothing, a workspace cache already exists
			}

			$this->workspacesCache = $GLOBALS['typo3CacheManager']->getCache('workspaces_cache');
		}
	}


	/**
	 * Put the generated dataArray into the workspace cache.
	 *
	 * @param array $versions All records uids etc. First key is table name, second key incremental integer. Records are associative arrays with uid, t3ver_oid and t3ver_swapmode fields. The pid of the online record is found as "livepid" the pid of the offline record is found in "wspid"
	 * @param string $filterTxt The given filter text from the grid.
	 */
	protected function setDataArrayIntoCache (array $versions, $filterTxt) {
		if (TYPO3_UseCachingFramework === TRUE) {
			$hash = $this->calculateHash($versions, $filterTxt);
			$this->workspacesCache->set($hash, $this->dataArray, array($this->currentWorkspace));
		}
	}


	/**
	 * Checks if a cache entry is given for given versions and filter text and tries to load the data array from cache.
	 *
	 * @param array $versions All records uids etc. First key is table name, second key incremental integer. Records are associative arrays with uid, t3ver_oid and t3ver_swapmode fields. The pid of the online record is found as "livepid" the pid of the offline record is found in "wspid"
	 * @param string $filterTxt The given filter text from the grid.
	 */
	protected function getDataArrayFromCache (array $versions, $filterTxt) {
		$cacheEntry = FALSE;

		if (TYPO3_UseCachingFramework === TRUE) {
			$hash = $this->calculateHash($versions, $filterTxt);

			$content = $this->workspacesCache->get($hash);

			if ($content !== FALSE) {
				$this->dataArray = $content;
				$cacheEntry = TRUE;
			}
		}

		return $cacheEntry;
	}

	/**
	 * Calculate the hash value of the used workspace, the user id, the versions array, the filter text, the sorting attribute, the workspace selected in grid and the sorting direction.
	 *
	 * @param array $versions All records uids etc. First key is table name, second key incremental integer. Records are associative arrays with uid, t3ver_oid and t3ver_swapmode fields. The pid of the online record is found as "livepid" the pid of the offline record is found in "wspid"
	 * @param string $filterTxt The given filter text from the grid.
	 */
	protected function calculateHash (array $versions, $filterTxt) {
		$hashArray = array(
			$GLOBALS['BE_USER']->workspace,
			$GLOBALS['BE_USER']->user['uid'],
			$versions,
			$filterTxt,
			$this->sort,
			$this->sortDir,
			$this->currentWorkspace);
		$hash = md5(serialize($hashArray));

		return $hash;
	}

	/**
	 * Performs sorting on the data array accordant to the
	 * selected column in the grid view to be used for sorting.
	 *
	 * @return void
	 */
	protected function sortDataArray() {
		if (is_array($this->dataArray)) {
			switch ($this->sort) {
				case 'uid';
				case 'change';
				case 'workspace_Tstamp';
				case 't3ver_oid';
				case 'liveid';
				case 'livepid':
					usort($this->dataArray, array($this, 'intSort'));
					break;
				case 'label_Workspace';
				case 'label_Live';
				case 'label_Stage';
				case 'workspace_Title';
				case 'path_Live':
						// case 'path_Workspace': This is the first sorting attribute
					usort($this->dataArray, array($this, 'stringSort'));
					break;
			}
		} else {
			t3lib_div::sysLog('Try to sort "' . $this->sort . '" in "tx_Workspaces_Service_GridData::sortDataArray" but $this->dataArray is empty! This might be the Bug #26422 which could not reproduced yet.', 3);
		}
	}

	/**
	 * Implements individual sorting for columns based on integer comparison.
	 *
	 * @param array $a
	 * @param array $b
	 * @return integer
	 */
	protected function intSort(array $a, array $b) {
			// Als erstes nach dem Pfad sortieren
		$path_cmp = strcasecmp($a['path_Workspace'], $b['path_Workspace']);

		if ($path_cmp < 0) {
			return $path_cmp;
		} elseif ($path_cmp == 0) {
			if ($a[$this->sort] == $b[$this->sort]) {
				return 0;
			}
			if ($this->sortDir == 'ASC') {
				return ($a[$this->sort] < $b[$this->sort]) ? -1 : 1;
			} elseif ($this->sortDir == 'DESC') {
				return ($a[$this->sort] > $b[$this->sort]) ? -1 : 1;
			}
		} elseif ($path_cmp > 0) {
			return $path_cmp;
		}
		return 0; //ToDo: Throw Exception
	}

	/**
	 * Implements individual sorting for columns based on string comparison.
	 *
	 * @param  $a
	 * @param  $b
	 * @return int
	 */
	protected function stringSort($a, $b) {
		$path_cmp = strcasecmp($a['path_Workspace'], $b['path_Workspace']);

		if ($path_cmp < 0) {
			return $path_cmp;
		} elseif ($path_cmp == 0) {
			if ($a[$this->sort] == $b[$this->sort]) {
				return 0;
			}
			if ($this->sortDir == 'ASC') {
				return (strcasecmp($a[$this->sort], $b[$this->sort]));
			} elseif ($this->sortDir == 'DESC') {
				return (strcasecmp($a[$this->sort], $b[$this->sort]) * (-1));
			}
		} elseif ($path_cmp > 0) {
			return $path_cmp;
		}
		return 0; //ToDo: Throw Exception
	}

	/**
	 * Determines whether the text used to filter the results is part of
	 * a column that is visible in the grid view.
	 *
	 * @param string $filterText
	 * @param array $versionArray
	 * @return boolean
	 */
	protected function isFilterTextInVisibleColumns($filterText, array $versionArray) {
		if (is_array($GLOBALS['BE_USER']->uc['moduleData']['Workspaces'][$GLOBALS['BE_USER']->workspace]['columns'])) {
			foreach ($GLOBALS['BE_USER']->uc['moduleData']['Workspaces'][$GLOBALS['BE_USER']->workspace]['columns'] as $column => $value) {
				if (isset($value['hidden']) && isset($column) && isset($versionArray[$column])) {
					if ($value['hidden'] == 0) {
						switch ($column) {
							case 'workspace_Tstamp':
								if (stripos($versionArray['workspace_Formated_Tstamp'], $filterText) !== FALSE) {
									return TRUE;
								}
								break;
							case 'change':
								if (stripos(strval($versionArray[$column]), str_replace('%', '', $filterText)) !== FALSE) {
									return TRUE;
								}
								break;
							default:
								if (stripos(strval($versionArray[$column]), $filterText) !== FALSE) {
									return TRUE;
								}
						}
					}
				}
			}
		}
		return FALSE;
	}

	/**
	 * Gets the state of a given state value.
	 *
	 * @param	integer	stateId of offline record
	 * @param	boolean	hidden flag of online record
	 * @param	boolean	hidden flag of offline record
	 * @return	string
	 */
	 protected function workspaceState($stateId, $hiddenOnline = FALSE, $hiddenOffline = FALSE) {
		switch ($stateId) {
			case -1:
				$state = 'new';
				break;
			case 1:
			case 2:
				$state = 'deleted';
				break;
			case 4:
				$state = 'moved';
				break;
			default:
				$state = 'modified';
		}

		if ($hiddenOnline == 0 && $hiddenOffline == 1) {
			$state = 'hidden';
		} elseif ($hiddenOnline == 1 && $hiddenOffline == 0) {
			$state = 'unhidden';
		}

		return $state;
	}

	/**
	 * Gets the field name of the enable-columns as defined in $TCA.
	 *
	 * @param string $table Name of the table
	 * @param string $type Type to be fetches (e.g. 'disabled', 'starttime', 'endtime', 'fe_group)
	 * @return string|NULL The accordant field name or NULL if not defined
	 */
	protected function getTcaEnableColumnsFieldName($table, $type) {
		$fieldName = NULL;

		if (!(empty($GLOBALS['TCA'][$table]['ctrl']['enablecolumns'][$type]))) {
			$fieldName = $GLOBALS['TCA'][$table]['ctrl']['enablecolumns'][$type];
		}

		return $fieldName;
	}
}


if (defined('TYPO3_MODE') && isset($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/workspaces/Classes/Service/GridData.php'])) {
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/workspaces/Classes/Service/GridData.php']);
}
?>
