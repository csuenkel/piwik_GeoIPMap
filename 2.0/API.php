<?php

/**
  *
 * GeoIPMap
 *
 * Copyright (c) 2012-2013, Christian Suenkel <info@suenkel.org>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 * * Redistributions of source code must retain the above copyright
 *   notice, this list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright
 *   notice, this list of conditions and the following disclaimer in
 *   the documentation and/or other materials provided with the
 *   distribution.
 *
 * * Neither the name of Christian Suenkel nor the names of his
 *   contributors may be used to endorse or promote products derived
 *   from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Christian Suenkel <christian@suenkel.de>
 * @link http://plugin.suenkel.org
 * @copyright 2012-2013 Christian Suenkel <info@suenkel.de>
 * @license http://www.opensource.org/licenses/BSD-3-Clause The BSD 3-Clause License
 * @category Piwik\Plugins
 * @package GeoIPMap
 */
namespace Piwik\Plugins\GeoIPMap;

use Piwik\Common;
use Piwik\Metrics;
use Piwik\Db;
use Piwik\Piwik as Piwik;
use Piwik\Archive as Archive;
use Piwik\Date;
use Piwik\Period;
use Piwik\DataTable;

require_once PIWIK_INCLUDE_PATH . '/core/Piwik.php';

/**
 * API-class
 * provides API-Access to the report (archived/live) data
 */
class API extends \Piwik\Plugin\API
{

    /**
     * retreive Archived Reports from Database
     *
     * @param integer $idSite            
     * @param string $period            
     * @param string $date            
     * @param string $segment            
     * @param boolean $expanded            
     * @return \Piwik\DataTable
     */
    protected function getDataTable($idSite, $period, $date, $segment, $expanded)
    {
        Piwik::checkUserHasViewAccess($idSite);
        $archive = Archive::build($idSite, $period, $date, $segment);
        $dataTable = $archive->getDataTable(Archiver::RECORD_NAME);
        // $dataTable->filter('Sort', array(Piwik\Archive::INDEX_NB_VISITS, 'desc', $naturalSort = false, $expanded));
        if ($expanded) {
            $dataTable->queueFilter('ReplaceColumnNames');
        }
        foreach ($dataTable->getRows() as $key => $row) {
            @list($lat,$lg) = explode('|', $row->getColumn('label'));
            $row->addMetadata('location_latitude', $lat);
            $row->addMetadata('location_longitude', $lg);
        }
        return $dataTable;
    }

    /**
     * Load archived aggreates from database
     *
     * @param integer $idSite            
     * @param string $period            
     * @param string $date            
     * @param string $segment            
     * @param boolean $expanded            
     * @return Piwik\DataTable
     */
    public function getVisits($idSite, $period, $date, $segment = false, $expanded = false)
    {
        Piwik::checkUserHasViewAccess($idSite);
        $dataTable = $this->getDataTable($idSite, $period, $date, $segment, $expanded);
        return $dataTable;
    }

    /**
     * provide a view on the last "n" visits
     *
     * @param integer $idSite            
     * @param integer $limit            
     * @param integer $timestamp            
     * @return Piwik\DataTable
     */
    public function getLiveVisits($idSite, $limit = 20, $timestamp = 0)
    {
        Piwik::checkUserHasViewAccess($idSite);
        
        // calculate the timeperiod for selection
        $currentSite = new \Piwik\Site($idSite);
        $currentTimezone = $currentSite->getTimezone();
        // This means the period starts 24 hours, so we lookback max one day
        $processedDate = Date::factory('yesterdaySameTime', $currentTimezone); // if not commented, the Period below fails ->setTimezone($currentTimezone);
        $processedPeriod = Period::factory('day', $processedDate);
        $mysqldate = $processedPeriod->getDateStart()
            ->getDatetime(); // ->toString('Y-m-d H:i:s');
        if ($timestamp) {
            $processedDate = Date::factory('@' . $timestamp);
            $timestamp = $processedDate->getDatetime();
            if ($timestamp > $mysqldate) $mysqldate = $timestamp;
        }
        
        // perform database query
        $select = "          concat(location_longitude,'|',location_latitude) as label,
                            continent as location_continent,
                            location_country,
		                    location_city,
                            location_latitude,
         		            location_longitude,
			    count(distinct idvisitor) as `" .
                 Metrics::INDEX_NB_UNIQ_VISITORS . "`,
			    count(*) as `" . Metrics::INDEX_NB_VISITS . "`,
                            max(visit_last_action_time) as visit_last_action_time";
        
        $from = Common::prefixTable('log_visit') . ',' . Common::prefixTable('continentcountry');
        
        $where = "      visit_last_action_time > ?
			       AND idsite = ?
	               AND not location_latitude is null
                   AND location_country = country";
        
        $groupsort = "GROUP BY label
	                  ORDER BY visit_last_action_time DESC
	                  LIMIT " . intVal($limit);
        
        $query = sprintf('SELECT %s FROM %s WHERE %s %s', $select, $from, $where, $groupsort);
        
        $sqlBind = array($mysqldate, $idSite);
        $result = DB::fetchAll($query, $sqlBind);
        $table = new DataTable();
        $table->addRowsFromSimpleArray($result);
        return $table;
    }
}