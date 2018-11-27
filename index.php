<?php
/**
 * MIT License
 */

/**
 * MySQL Database configuration
 */
$mysqlConfig = [
    'host' => '192.168.56.101',
    'port' => 3306,
    'username' => 'statusengine',
    'password' => 'password',
    'database' => 'statusengine'
];

/**
 * Set START and END date of report as unix timestamp
 */
$timerange = [
    'start' => strtotime('1 year ago'),
    'end' => time()
];

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s',
    $mysqlConfig['host'],
    $mysqlConfig['port'],
    $mysqlConfig['database']
);

$Connection = new \PDO($dsn, $mysqlConfig['username'], $mysqlConfig['password']);
$Connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
//Enable UTF-8
$query = $Connection->prepare('SET NAMES utf8');
$query->execute();

//Select all Services
$query = $Connection->prepare('SELECT hostname, service_description FROM statusengine_servicestatus ORDER BY hostname ASC, service_description ASC');
$query->execute();
$result = $query->fetchAll(\PDO::FETCH_ASSOC);
//print_r($result);

/********** Calculate Report ***************/

//Select state changes for given Service
$report = [];
$statsStartTime = time();
foreach ($result as $hostAndService) {
    $host = $hostAndService['hostname'];
    $service = $hostAndService['service_description'];

    $query = $Connection->prepare(
        'SELECT * FROM statusengine_service_statehistory
                  WHERE state_time > ? AND state_time < ? AND is_hardstate = 1
                  AND hostname = ? AND service_description = ?
                  ORDER BY state_change ASC');
    $query->bindParam(1, $timerange['start']);
    $query->bindParam(2, $timerange['end']);
    $query->bindParam(3, $host);
    $query->bindParam(4, $service);

    $query->execute();
    $statehistory = $query->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($statehistory as $record) {
        if (!isset($report[$host][$service])) {
            $report[$host][$service] = [
                'outages' => 0,
                'time_ok' => 0,
                'time_error' => 0,
            ];
            $lastStateChange = $timerange['start'];
        }

        $timeDiff = $record['state_time'] - $lastStateChange;
        $lastStateChange = $record['state_time'];
        if ($record['state'] == 0) {
            $report[$host][$service]['time_ok'] += $timeDiff;
        } else {
            $report[$host][$service]['time_error'] += $timeDiff;
            $report[$host][$service]['outages']++;
        }
    }
}
$took = (time() - $statsStartTime);

/********** Output Report to CLI ***************/
out('---------------------------------------- Statusengine availability report example ----------------------------------------');
out(sprintf('From: %s', date('d.m.Y H:i:s', $timerange['start'])));
out(sprintf('To:   %s', date('d.m.Y H:i:s', $timerange['end'])));
out(sprintf('Generation of report took %s seconds', $took));
out('');
out('--------------------------------------------------- Outages ---------------------------------------------------');
foreach ($report as $hostname => $services) {
    out(sprintf('Host: %s', $hostname));
    if(!empty($services)){
        out(column(' ', 4), false);
        out(column('Service', 25), false);
        out(column('Outages', 12), false);
        out(column('Time Ok', 60), false);
        out(column('Time in error state', 60), true);
        out('---------------------------------------------------------------------------------------------------------------');
    }
    foreach ($services as $servicename => $reportData) {
        out(column(' ', 4), false);
        out(column($servicename, 25), false);
        out(column($reportData['outages'], 12), false);
        out(column(secondsForHuman($reportData['time_ok']), 60), false);
        out(column(secondsForHuman($reportData['time_error']), 60), true);
    }
    out('================================================================================================================');
}

/********** CLI format helper functions ***************/
function out($msg = '', $newLine = true)
{
    echo $msg;
    if ($newLine)
        echo PHP_EOL;
}

function secondsForHuman($duration)
{
    if ($duration == '') {
        $duration = 0;
    }
    $zero = new \DateTime("@0");
    $seconds = new \DateTime("@$duration");
    $closure = function ($duration) {
        //Check how mutch "time" we need
        if ($duration >= 31536000) {
            // 1 year or more
            return '%y years, %m months, %d days, %h hours, %i minutes, and %s seconds';
        } else if ($duration >= 2678400) {
            // 1 month or more
            return '%m months, %d  days, %h  hours, %i  minutes and %s  seconds';
        } else if ($duration >= 86400) {
            // 1 day or more
            return '%a days, %h hours, %i minutes and %s seconds';
        } else if ($duration >= 3600) {
            // 1 hour or more
            return '%h hours, %i minutes and %s seconds';
        } else if ($duration >= 60) {
            // 1 minute or more
            return '%i minutes and %s seconds';
        } else if ($duration >= 0) {
            // 0 second or more
            return '%s seconds';
        }
    };
    $format = $closure($duration);
    return $zero->diff($seconds)->format($format);
}

function column($txt = '', $len = 4){
    if(strlen($txt) < $len){
        while(strlen($txt) < $len){
            $txt .= ' ';
        }
        return $txt;
    }

    if(strlen($txt) > ($len - 3)){
        $txt = substr($txt, 0, ($len -3));
        $txt .= '...';
        return $txt;
    }

    return $txt;
}