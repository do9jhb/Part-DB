<?php
/*
    Part-DB Version 0.4+ "nextgen"
    Copyright (C) 2017 Jan Böhmer
    https://github.com/jbtronics

    This program is free software; you can redistribute it and/or
    modify it under the terms of the GNU General Public License
    as published by the Free Software Foundation; either version 2
    of the License, or (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA
*/

include_once('start_session.php');

use PartDB\Database;
use PartDB\HTML;
use PartDB\Log;
use PartDB\Manufacturer;
use PartDB\Part;
use PartDB\Supplier;
use PartDB\User;

$messages = array();
$fatal_error = false; // if a fatal error occurs, only the $messages will be printed, but not the site content
$starttime = microtime(true); // this is to measure the time while debugging is active

/********************************************************************************
 *
 *   Evaluate $_REQUEST
 *
 *********************************************************************************/

$supplier_id        = isset($_REQUEST['sid'])               ? (integer)$_REQUEST['sid']             : 0;
$with_subsuppliers = isset($_REQUEST['subsup'])            ? (boolean)$_REQUEST['subsup']          : true;
$table_rowcount     = isset($_REQUEST['table_rowcount'])    ? (integer)$_REQUEST['table_rowcount']  : 0;

$action = 'default';
if (isset($_REQUEST['subman_button'])) {
    $action = 'change_subman_state';
}
$selected_part_id = 0;
for ($i=0; $i<$table_rowcount; $i++) {
    $selected_part_id = isset($_REQUEST['id_'.$i]) ? (integer)$_REQUEST['id_'.$i] : 0;

    if (isset($_REQUEST['decrement_'.$i])) {
        $action = 'decrement';
        break;
    }

    if (isset($_REQUEST['increment_'.$i])) {
        $action = 'increment';
        break;
    }
}

/********************************************************************************
 *
 *   Initialize Objects
 *
 *********************************************************************************/

$html = new HTML($config['html']['theme'], $config['html']['custom_css'], _('Teileansicht'));

try {
    $database           = new Database();
    $log                = new Log($database);
    $current_user       = User::getLoggedInUser($database, $log);

    if ($supplier_id < 1) {
        throw new Exception(_('Es wurde keine gültige Kategorien-ID übermittelt!'));
    }

    $supplier = new Supplier($database, $current_user, $log, $supplier_id);

    if ($selected_part_id > 0) {
        $part = new Part($database, $current_user, $log, $selected_part_id);
    } else {
        $part = null;
    }

    $html->setTitle(_('Teileansicht') . ': ' . $supplier->getName());
} catch (Exception $e) {
    $messages[] = array('text' => nl2br($e->getMessage()), 'strong' => true, 'color' => 'red');
    $fatal_error = true;
}

/********************************************************************************
 *
 *   Execute actions
 *
 *********************************************************************************/

if (! $fatal_error) {
    switch ($action) {
        case 'change_subcat_state':
            $reload_site = true;
            break;

        case 'decrement': // remove one part
            try {
                if (! is_object($part)) {
                    throw new Exception('Es wurde keine gültige Bauteil-ID übermittelt!');
                }

                $part->setInstock($part->getInstock() - 1);

                $reload_site = true;
            } catch (Exception $e) {
                $messages[] = array('text' => nl2br($e->getMessage()), 'strong' => true, 'color' => 'red');
            }
            break;

        case 'increment': // add one part
            try {
                if (! is_object($part)) {
                    throw new Exception(_('Es wurde keine gültige Bauteil-ID übermittelt!'));
                }

                $part->setInstock($part->getInstock() + 1);

                $reload_site = true;
            } catch (Exception $e) {
                $messages[] = array('text' => nl2br($e->getMessage()), 'strong' => true, 'color' => 'red');
            }
            break;
    }
}

if (isset($reload_site) && $reload_site && (! $config['debug']['request_debugging_enable'])) {
    // reload the site to avoid multiple actions by manual refreshing
    header('Location: show_manufacturer_parts.php?mid='.$manufacturer_id.'&subcat='.$with_subsuppliers);
}

/********************************************************************************
 *
 *   Generate Table
 *
 *********************************************************************************/

if (! $fatal_error) {
    try {
        $parts = $supplier->getParts($with_subsuppliers, true);
        $table_loop = Part::buildTemplateTableArray($parts, 'supplier_parts');
        $html->setVariable('table_rowcount', count($parts), 'integer');
        $html->setLoop('table', $table_loop);
    } catch (Exception $e) {
        $messages[] = array('text' => nl2br($e->getMessage()), 'strong' => true, 'color' => 'red');
        $fatal_error = true;
    }
}

$php_endtime = microtime(true); // For Debug informations

/********************************************************************************
 *
 *   Set the rest of the HTML variables
 *
 *********************************************************************************/


$html->setVariable('with_subsuppliers', $with_subsuppliers, 'boolean');

if (! $fatal_error) {
    $html->setVariable('sid', $supplier->getID(), 'integer');
    $html->setVariable('supplier_name', $supplier->getName(), 'string');
    $html->setVariable('disable_footprints', ($config['footprints']['disable']), 'boolean');
    $html->setVariable('disable_manufacturers', ($config['manufacturers']['disable']), 'boolean');
    $html->setVariable('disable_auto_datasheets', ($config['auto_datasheets']['disable']), 'boolean');

    $html->setVariable('use_modal_popup', $config['popup']['modal'], 'boolean');
    $html->setVariable('popup_width', $config['popup']['width'], 'integer');
    $html->setVariable('popup_height', $config['popup']['height'], 'integer');
}

/********************************************************************************
 *
 *   Generate HTML Output
 *
 *********************************************************************************/


//If a ajax version is requested, say this the template engine.
if (isset($_REQUEST["ajax"])) {
    $html->setVariable("ajax_request", true);
}



$reload_link = $fatal_error ? 'show_supplier_parts.php?sid='.$supplier_id  : ''; // an empty string means that the...
$html->printHeader($messages, $reload_link);                                   // ...reload-button won't be visible


if (! $fatal_error) {
    $html->printTemplate('show_supplier_parts');
}

// If debugging is enabled, print some debug informations
$debug_messages = array();
if ((! $fatal_error) && ($config['debug']['enable'])) {
    $endtime = microtime(true);
    $lifetime = (integer)(1000*($endtime - $starttime));
    $php_lifetime = (integer)(1000*($php_endtime - $starttime));
    $html_lifetime = (integer)(1000*($endtime - $php_endtime));
    $debug_messages[] = array('text' => 'Debug-Meldungen: ', 'strong' => true, 'color' => 'darkblue');
    $debug_messages[] = array('text' => 'Anzahl Teile mit diesem Hersteller: '.(count($parts)), 'color' => 'darkblue');
    $debug_messages[] = array('text' => 'Gesamte Laufzeit: '.$lifetime.'ms', 'color' => 'darkblue');
    $debug_messages[] = array('text' => 'PHP Laufzeit: '.$php_lifetime.'ms', 'color' => 'darkblue');
    $debug_messages[] = array('text' => 'HTML Laufzeit: '.$html_lifetime.'ms', 'color' => 'darkblue');
}

$html->printFooter($debug_messages);