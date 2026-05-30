<?php
declare(strict_types=1);
/**
 *  ------------------------------------------------------------------------
 *  samlSSO
 *
 *  samlSSO was inspired by the initial work of Derrick Smith's
 *  PhpSaml. This project's intend is to address some structural issues
 *  caused by the gradual development of GLPI and the broad amount of
 *  wishes expressed by the community.
 *
 *  Copyright (C) 2026 by DonutsNL
 *  ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of samlSSO plugin for GLPI.
 *
 * samlSSO plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * samlSSO is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with samlSSO. If not, see <http://www.gnu.org/licenses/> or
 * https://choosealicense.com/licenses/gpl-3.0/
 *
 * ------------------------------------------------------------------------
 *
 *  @package    samlSSO
 *  @version    1.3.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.3.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\Utility;

use CronTask as glpiCronTask;
use DateTime;
use DateTimeZone;

class GeoIPUpdater
{
    /**
     * Run the GeoIP database update process.
     *
     * @param glpiCronTask|null $task Optional GLPI CronTask for logging/volume tracking.
     * @return bool True on success, false on failure.
     */
    public static function run(?glpiCronTask $task = null): bool
    {
        $destDir = __DIR__;
        $destFile = $destDir . '/ip_to_country.bin';

        self::log("Starting GeoIP offline database compiler...", $task);

        $downloaded = false;
        $tempGz = tempnam(sys_get_temp_dir(), 'dbip_');

        for ($i = 0; $i < 6; $i++) {
            $date = new DateTime('now', new DateTimeZone('UTC'));
            $date->modify("-$i month");
            $ym = $date->format('Y-m');
            $url = "https://download.db-ip.com/free/dbip-country-lite-{$ym}.csv.gz";

            self::log("Attempting to download DB-IP Lite for {$ym}...", $task);
            self::log("URL: $url", $task);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && !empty($data)) {
                file_put_contents($tempGz, $data);
                self::log("Successfully downloaded database for {$ym}.", $task);
                $downloaded = true;
                break;
            } else {
                self::log("Failed with HTTP code $httpCode.", $task);
            }
        }

        if (!$downloaded) {
            self::log("Could not download DB-IP Lite CSV database. Please verify internet access or URL updates.", $task);
            if (file_exists($tempGz)) {
                unlink($tempGz);
            }
            return false;
        }

        self::log("Compiling CSV data to optimized binary format...", $task);

        if (!function_exists('gzopen')) {
            self::log("Error: zlib PHP extension is required to decompress the database.", $task);
            unlink($tempGz);
            return false;
        }

        $gz = gzopen($tempGz, 'rb');
        if (!$gz) {
            self::log("Error opening downloaded gzip file.", $task);
            unlink($tempGz);
            return false;
        }

        $out = fopen($destFile, 'wb');
        if (!$out) {
            self::log("Error creating output file $destFile.", $task);
            gzclose($gz);
            unlink($tempGz);
            return false;
        }

        $count = 0;
        while (!gzeof($gz)) {
            $line = gzgets($gz, 4096);
            if ($line === false) {
                break;
            }

            $row = str_getcsv($line);
            if (count($row) < 3) {
                continue;
            }

            $startIp = $row[0];
            $endIp = $row[1];
            $country = $row[2];

            // Only support IPv4 ranges for this compact binary format
            if (filter_var($startIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
                filter_var($endIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {

                $startLong = ip2long($startIp);
                $endLong = ip2long($endIp);

                if ($startLong !== false && $endLong !== false) {
                    // Pack as: 4-byte unsigned int start, 4-byte unsigned int end, 2-byte ASCII country
                    $packed = pack('NNA2', $startLong, $endLong, $country);
                    fwrite($out, $packed);
                    $count++;
                }
            }
        }

        fclose($out);
        gzclose($gz);
        unlink($tempGz);

        self::log("Compilation finished successfully! Compiled $count IPv4 ranges to $destFile.", $task);

        if ($task !== null && method_exists($task, 'setVolume')) {
            $task->setVolume($count);
        }

        return true;
    }

    /**
     * Log messages either to the GLPI CronTask log system or echo to CLI stdout.
     *
     * @param string $msg
     * @param glpiCronTask|null $task
     * @return void
     */
    private static function log(string $msg, ?glpiCronTask $task = null): void
    {
        if ($task !== null && method_exists($task, 'log')) {
            $task->log($msg);
        } else {
            echo $msg . "\n";
        }
    }
}
