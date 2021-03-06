<?php
/*
*******************************************************
***													***
*** backpack										***
*** Cedric MONTUY pour CHG-WEB                      ***
*** Original author : Yoshi Sakai					***
***													***
*******************************************************
*/

use Xmf\Module\Admin;
use Xmf\Request;

/** @var Admin $adminObject */

require_once __DIR__ . '/admin_header.php';
xoops_cp_header();
$adminObject = Admin::getInstance();
$adminObject->displayNavigation(basename(__FILE__));

$bp = new backpack();
if ($bp->err_msg) {
    sprintf('<span style="color: red; ">%s</span>', $bp->err_msg);
}
function mysqli_tablename($result, $i)
{
    mysqli_data_seek($result, $i);
    $f     = mysqli_fetch_array($result);
    $fetch = null !== $f ? $f[0] : null;
    return $fetch;
}

$time_start = time();
//$dump_buffer = null;
$dump_line      = 0;
$dump_size      = 0;
$download_count = 0;
$download_fname = [];
$mime_type      = '';
$query_res      = []; // for query result

if (isset($_POST['purgeallfiles'])) {
    $bp->purge_allfiles();
    redirect_header('./index.php', 1, _AM_PURGED_ALLFILES);
}
// Make sure we pick up variables passed via URL
$mode       = Request::getString('mode', '', 'GET');
$action     = Request::getString('action', '', 'GET');
$num_tables = Request::getString('num_tables', '', 'GET');
$checkall   = Request::getString('checkall', '', 'GET');

$tr_comp = '<tr><td class="odd"><strong>'
           . _AM_COMPRESSION
           . '</strong></td>'
           . '<td><input type="radio" id="gzip" name="file_compression" value="gzip" checked>'
           . '<label for="gz">gzip</label>&nbsp;&nbsp;'
           . '<input type="radio" id="zip" name="file_compression" value="zip">'
           . '<label for="sql">zip</label>&nbsp;&nbsp'
           . '<input type="radio" id="plain" name="file_compression" value="none">'
           . '<label for="sql">text</label>&nbsp;&nbsp</td></tr>';
$tr_strd = '<tr><td class="odd" style="width:30%;"><strong>' . _AM_DETAILSTOBACKUP . '</strong></td>' . '<td><input type="checkbox" name="structure" checked>&nbsp;' . _AM_TABLESTRUCTURE . '&nbsp;' . '<input type="checkbox" name="data" checked>&nbsp;' . _AM_TABLEDATA . '&nbsp;</td></tr>';

// Handle URL actions
switch ($mode) {
    case POST_SELECT_MODULE_FORM:
    {
        $select_dirname = isset($_GET['dirname']) ? filter_input(INPUT_GET, 'dirname', FILTER_SANITIZE_STRING) : 0;
        $mod_selections = $bp->make_module_selection($select_dirname);
        echo '<form method="post" action="index2.php?mode=' . POST_SELECT_TABLES_FORM . '&amp;alltables=on">';
        echo '<table class="outer" style="width:100%;"><tr><td class="head" colspan=2>' . _AM_MODULEBACKUP . '</td></tr>';
        echo '<tr><td class="odd"><strong>' . _AM_SELECTMODULE . '</strong></td><td>' . $mod_selections . '</td></tr>';
        echo $tr_strd;
        echo $tr_comp;
        echo '<tr><td colspan=2 style="text-align: center;"><input type="submit" value="' . _AM_BACKUP . '"></td></tr></table>';
        echo '</form>';
        //echo '</p>';
        echo '<br>';
        break;
    }
    case POST_DB_SELECT_FORM:
    {
        $select_dirname = isset($_GET['dirname']) ? filter_input(INPUT_GET, 'dirname', FILTER_SANITIZE_STRING) : 0;
        $mod_selections = $bp->make_module_selection($select_dirname, 1);
        // Get list of tables in the database and output form
        if ('module' == $action && $dirname) {
            $result     = get_module_tables($dirname);
            $num_tables = count($result);
            $checkall   = true;
        } else {
            $result     = $xoopsDB->queryF('SHOW TABLES FROM ' . $db_selected);
            $num_tables = $xoopsDB->getRowsNum($result);
        }
        echo '<table class="outer" style="width:100%">';
        echo '<form method="post" action="index2.php?mode=' . POST_SELECT_TABLES_FORM . '&num_tables=' . $num_tables . '">';
        echo '<tr><td class="head" colspan="2"><strong>' . _AM_SELECTTABLES . '</strong></td></tr>';
        echo '<tr><td class="main_left" colspan="2"><p>' . _AM_BACKUPNOTICE . '</p>';
        echo '<p><strong>' . _AM_SELECTTABLE . '</strong></p>';
        $checked = (!empty($checkall) ? ' checked' : '');
        for ($i = 0; $i < $num_tables; ++$i) {
            if ('module' == $action && $dirname) {
                $tablename = $xoopsDB->prefix($result[$i]);
            } else {
                $tablename = mysqli_tablename($result, $i);
            }
            $checkbox_string = sprintf(
                '<input type="checkbox" name="check_id%d" $checked>
				<input type="hidden" name="tablename%d" value="%s">&nbsp;%s<br>' . "\n",
                $i,
                $i,
                $tablename,
                $tablename
            );
            echo '<tr><td class="main_left" colspan="2">' . $checkbox_string . '</td></tr>';
        }
        if ('module' == $action && $dirname) {
            echo '<input type="hidden" name="dirname" value="' . $dirname . '>';
        }
        echo '<tr><td colspan="2">';
        echo '<a href="' . XOOPS_URL . '/modules/backpack/admin/index.php?mode=' . POST_DB_SELECT_FORM . '&amp;action=backup&amp;checkall=1">' . _AM_CHECKALL . '</a></td></tr>';
        echo $tr_strd;
        echo $tr_comp;
        echo '<tr><td colspan="2">';
        echo '<p><input type="submit" value="' . _AM_BACKUP . '">';
        echo '<input type="reset" value="' . _AM_RESET . '">';
        echo '</p></td></tr></form></table>';
        break;
    }
    case POST_SELECT_TABLES_FORM:
    {
        $bp->purge_allfiles();

        $sql_string = '';
        $alltables  = $backup_structure = $backup_data = 0;
        if (isset($_GET['alltables'])) {
            $alltables = ('on' == filter_input(INPUT_GET, 'alltables', FILTER_SANITIZE_STRING)) ? 1 : 0;
        }
        if (isset($_POST['alltables'])) {
            $alltables = ('on' == filter_input(INPUT_POST, 'alltables', FILTER_SANITIZE_STRING)) ? 1 : 0;
        }
        if (isset($_POST['structure'])) {
            $backup_structure = ('on' == filter_input(INPUT_POST, 'structure', FILTER_SANITIZE_STRING)) ? 1 : 0;
        }
        if (isset($_POST['data'])) {
            $backup_data = ('on' == filter_input(INPUT_POST, 'data', FILTER_SANITIZE_STRING)) ? 1 : 0;
        }
        $dirname = isset($_POST['dirname']) ? filter_input(INPUT_POST, 'dirname', FILTER_SANITIZE_STRING) : 0;
        if ($dirname) {
            if (0 == strcmp($dirname, 'system')) {
                $result = $sys_tables;
            } else {
                $result = $bp->get_module_tables($dirname);
            }
            $num_tables = count($result);
        } else {
            $result     = $xoopsDB->queryF('SHOW TABLES FROM ' . $db_selected);
            $num_tables = $xoopsDB->getRowsNum($result);
        }
        $j               = 0;
        $tablename_array = [];
        if (!$alltables) {
            for ($i = 0; $i < $num_tables; ++$i) {
                $check_id  = sprintf('check_id%d', $i);
                $tablename = sprintf('tablename%d', $i);

                if (isset($_POST[$check_id])) {
                    if (isset($_POST[$tablename])) {
                        $tablename_array[$j] = filter_input(INPUT_POST, $tablename, FILTER_SANITIZE_STRING);
                        ++$j;
                    }
                }
            }
        } else {
            for ($i = 0; $i < $num_tables; ++$i) {
                if ($dirname) {
                    $tablename_array[$i] = $xoopsDB->prefix($result[$i]);
                } else {
                    $tablename_array[$i] = mysqli_tablename($result, $i);
                }
            }
        }
        if ($dirname) {
            $filename = $dirname . date('YmdHis', time());
        } elseif ($alltables) {
            $filename = 'xdb' . date('YmdHis', time());
        } else {
            $filename = 'xtbl' . date('YmdHis', time());
        }
        $cfgZipType = filter_input(INPUT_POST, 'file_compression', FILTER_SANITIZE_STRING); //$_POST['file_compression'] ;
        $bp->backup_data($tablename_array, $backup_structure, $backup_data, $filename, $cfgZipType);
        $download_fname = $bp->download_fname();
        if (1 == $bp->download_count) {
            //redirect_header("./download.php?url=".$download_fname[0]['filename'], 1, _AM_READY_TO_DOWNLOAD);
            $url     = './download.php?url=' . $download_fname[0]['filename'];
            $time    = 1;
            $message = _AM_READY_TO_DOWNLOAD;
            $url     = preg_replace('/&amp;/i', '&', htmlspecialchars($url, ENT_QUOTES));
            echo '
            <html>
            <head>
            <title>' . htmlspecialchars($xoopsConfig['sitename'], ENT_QUOTES | ENT_HTML5) . '</title>
            <meta http-equiv="Content-Type" content="text/html; charset=' . _CHARSET . '">
            <meta http-equiv="Refresh" content="' . $time . '; url=' . $url . '">
            <style type="text/css">
                    body {background-color : #fcfcfc; font-size: 12px; font-family: Trebuchet MS,Verdana, Arial, Helvetica, sans-serif; margin: 0px;}
                    .redirect {width: 70%; margin: 110px; text-align: center; padding: 15px; border: #e0e0e0 1px solid; color: #666666; background-color: #f6f6f6;}
                    .redirect a:link {color: #666666; text-decoration: none; font-weight: bold;}
                    .redirect a:visited {color: #666666; text-decoration: none; font-weight: bold;}
                    .redirect a:hover {color: #999999; text-decoration: underline; font-weight: bold;}
            </style>
            </head>
            <body>
            <div align="center">
            <div class="redirect">
              <span style="font-size: 16px; font-weight: bold;">' . $message . '</span>
              <hr style="height: 3px; border: 3px #E18A00 solid; width: 95%;">
              <p>' . sprintf(_AM_IFNOTRELOAD, $url) . '</p>
            </div>
            </div>
            </body>
            </html>';
        } else {
            $form = new XoopsThemeForm(_AM_DOWNLOAD_LIST, 'download', $_SERVER['PHP_SELF']);
            $iMax = count($download_fname);
            for ($i = 0; $i < $iMax; ++$i) {
                $url = '<a href="download.php?url=' . $download_fname[$i]['filename'] . '" target="_blank">' . $download_fname[$i]['filename'] . '</a>';
                $url .= $download_fname[$i]['line'] . 'lines ' . $download_fname[$i]['size'] . 'bytes<br>';
                $form->addElement(new XoopsFormLabel($i, $url));
            }
            $form->addElement(new XoopsFormButton('', 'purgeallfiles', _AM_PURGE_FILES, 'submit'));
            $form->display();
        }
        break;
    }

    case DB_SELECT_FORM:
    {
        echo '<table cellspacing="0" cellpadding="3">';
        if ('backup' == $action) {
            echo '<tr><td class="title">' . _AM_TITLE_BCK . '</td></tr>';
            echo '<tr><td class="main_left"><p><b>' . _AM_SELECT_DATABASE . '</b>';
        }
        if ('backup' == $action) {
            echo '<form method="post" action="index2.php?mode=' . POST_DB_SELECT_FORM . '">';
        }
        echo '<input type="submit" value="Restore">';
        echo '</form></p></td></tr></table>';
        break;
    }
    default:
    {
        $result     = $xoopsDB->queryF('SHOW TABLES FROM ' . $db_selected);
        $num_tables = $xoopsDB->getRowsNum($result);
        echo '<form method="post" action="index2.php?mode=' . POST_SELECT_TABLES_FORM . '&amp;num_tables=' . $num_tables . '&amp;alltables=on">';
        echo '<table class="outer" style="width:100%;"><tr><td class="head" colspan="2">' . _AM_BACKUPTITLE . '</td></tr>';
        echo $tr_strd;
        echo $tr_comp;
        echo '<tr><td colspan="2" style="text-align: center;"><input type="submit" value="' . _AM_BACKUP . '"></td></tr></table></form>';
        echo '<br>';
    }
}
require __DIR__ . '/admin_footer.php';
