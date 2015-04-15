<?php

/**
 * Report incoming broken links
 */

class FourOhFourReport extends SS_Report {

	public function title() {
		return _t('FourOhFourLogger.FOUROHFOURREPORT',"External broken links report");
	}
	
	public function parameterFields() {
		return false;
	}
	
	public function sourceRecords($params, $sort, $limit) {
		
		return FourOhFourLog::get();
	}
	
	public function columns() {
		
		$fields = array(
			"Count" => array(
				"title" => _t('FourOhFourLogger.HitCount', 'Amount of hits')
			),
			'Link' => array(
				'title' => _t('FourOhFourLogger.Link', 'URL')
			),
			"Referrer" => array(
				"title" => _t('FourOhFourLogger.Referrer', "Referrer")
			),
			"LastEdited" => array(
				"title" => _t('FourOhFourLogger.LastHit', 'Most recent hit'),
				'casting' => 'SS_Datetime->Full'
			)
		);
		
		return $fields;
	}
}
